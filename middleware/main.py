import datetime
import json
import os
import re
import socket
import threading
import time
import urllib.error
import urllib.request
import uuid

import paramiko
import ping3
from fastapi import FastAPI, HTTPException
from netmiko import ConnectHandler
from pydantic import BaseModel

app = FastAPI(title="NetAuto Middleware")

# --- Syslog listener: detects operationally significant events (any facility, not just
# OSPF/BGP) and reports them to the Laravel backend, which dispatches an AI investigation.
# The AI reads the raw message itself, so we don't need per-facility parsing rules here —
# just enough structure (facility/severity/mnemonic) to filter noise and label the incident. ---
SYSLOG_PORT = int(os.environ.get("SYSLOG_PORT", "5514"))
BACKEND_URL = os.environ.get("BACKEND_URL", "http://127.0.0.1:8000").rstrip("/")
MIDDLEWARE_INTERNAL_SECRET = os.environ.get(
    "MIDDLEWARE_INTERNAL_SECRET", "3fdeb5d8913a29fe674689de050059939f2b0d977271fb8b"
)

# Every Cisco IOS syslog message follows %FACILITY-SEVERITY-MNEMONIC: text — this is the
# universal structure regardless of whether it's OSPF, BGP, an interface, hardware, etc.
CISCO_SYSLOG_RE = re.compile(r"%([A-Z0-9_]+)-(\d)-([A-Z0-9_]+):\s*(.*)", re.IGNORECASE)

# Cisco severities: 0 Emergency .. 7 Debugging. Warning (4) and worse always trigger an
# incident. Notice (5) is a mixed bag — it includes real state-change events (OSPF/BGP
# ADJCHG, interface UPDOWN) but also routine admin noise (e.g. "Configured from console"),
# so at that level we only trigger for mnemonics known to represent an actual state change.
SEVERITY_THRESHOLD = 4
NOTICE_MNEMONICS_OF_INTEREST = {"ADJCHG", "ADJCHANGE", "UPDOWN"}

# Known-benign messages that would otherwise pass the severity threshold. IOU/EVE-NG labs
# without a TFTP config server retry this on every boot (every ~30-40s) — real hardware
# with no "boot system" configured does the same. It's not an actionable problem.
EXCLUDED_MNEMONICS = {"CONFIG_RESOLVE_FAILURE"}

# Best-effort "before -> after" extraction for the common state-transition message shapes.
# When a message doesn't match one of these, previous/new state are left null and the AI
# just reads the raw message — this is a display nicety, not a requirement for detection.
# \w+ (not \S+) so trailing punctuation, e.g. "to DOWN, Neighbor Down: ...", isn't captured.
STATE_TRANSITION_PATTERNS = [
    re.compile(r"from\s+(\w+)\s+to\s+(\w+)", re.IGNORECASE),
    re.compile(r"changed state to\s+(\w+)", re.IGNORECASE),
    re.compile(r"\bneighbor\s+\S+\s+(Up|Down)\b", re.IGNORECASE),
]


def _should_report(severity, mnemonic):
    if mnemonic.upper() in EXCLUDED_MNEMONICS:
        return False
    if severity <= SEVERITY_THRESHOLD:
        return True
    return severity == 5 and mnemonic.upper() in NOTICE_MNEMONICS_OF_INTEREST


def _extract_state_transition(message):
    for pattern in STATE_TRANSITION_PATTERNS:
        match = pattern.search(message)
        if not match:
            continue
        groups = match.groups()
        if len(groups) == 2:
            return groups[0], groups[1]
        if len(groups) == 1:
            return None, groups[0]
    return None, None


def _report_incident(device_ip, facility, severity, mnemonic, previous_state, new_state, raw_message):
    payload = json.dumps({
        "device_ip": device_ip,
        "facility": facility,
        "severity": severity,
        "mnemonic": mnemonic,
        "previous_state": previous_state,
        "new_state": new_state,
        "raw_message": raw_message,
    }).encode("utf-8")

    request = urllib.request.Request(
        f"{BACKEND_URL}/api/internal/syslog-event",
        data=payload,
        method="POST",
        headers={
            "Content-Type": "application/json",
            "Accept": "application/json",
            "X-Internal-Secret": MIDDLEWARE_INTERNAL_SECRET,
        },
    )

    try:
        with urllib.request.urlopen(request, timeout=10) as response:
            response.read()
    except urllib.error.URLError as exc:
        print(f"[syslog] failed to report incident to backend: {exc}")


def _handle_syslog_message(source_ip, message):
    match = CISCO_SYSLOG_RE.search(message)
    if not match:
        return

    facility, severity_str, mnemonic, _rest = match.groups()
    severity = int(severity_str)

    if not _should_report(severity, mnemonic):
        return

    previous_state, new_state = _extract_state_transition(message)
    _report_incident(source_ip, facility.upper(), severity, mnemonic.upper(), previous_state, new_state, message)


def _syslog_listener_loop():
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    try:
        sock.bind(("0.0.0.0", SYSLOG_PORT))
    except OSError as exc:
        print(f"[syslog] failed to bind UDP port {SYSLOG_PORT}: {exc}")
        return

    print(f"[syslog] listening for syslog messages on UDP {SYSLOG_PORT}")

    while True:
        try:
            data, addr = sock.recvfrom(4096)
        except OSError:
            continue

        message = data.decode("utf-8", errors="ignore").strip()
        # Strip an optional RFC3164 "<PRI>" prefix, e.g. "<189>" — Cisco includes it
        # depending on "service timestamps" / "logging origin-id" config.
        message = re.sub(r"^<\d+>", "", message)

        _handle_syslog_message(addr[0], message)


@app.on_event("startup")
def _start_syslog_listener():
    thread = threading.Thread(target=_syslog_listener_loop, daemon=True)
    thread.start()

TELNET_IAC = 255
TELNET_DONT = 254
TELNET_DO = 253
TELNET_WONT = 252
TELNET_WILL = 251
TELNET_SB = 250
TELNET_SE = 240
TELNET_OPT_BINARY = 0
TELNET_OPT_ECHO = 1
TELNET_OPT_SGA = 3


class TelnetSession:
    def __init__(self, host: str, port: int, sock: socket.socket):
        self.id = str(uuid.uuid4())
        self.host = host
        self.port = port
        self.socket = sock
        self.socket_lock = threading.Lock()
        self.buffer_lock = threading.Lock()
        self.closed = False
        self.created_at = time.time()
        self.last_activity = self.created_at
        self.read_buffer = []
        self.reader_thread = None


TELNET_SESSIONS: dict[str, TelnetSession] = {}
TELNET_SESSIONS_LOCK = threading.Lock()

class DeviceConfig(BaseModel):
    device_type: str = 'cisco_ios'
    host: str
    username: str
    password: str
    command: str | None = None
    commands: list[str] | None = None


class PingRequest(BaseModel):
    host: str


class TelnetConnectRequest(BaseModel):
    host: str
    port: int = 23


class TelnetInputRequest(BaseModel):
    session_id: str
    data: str


class TelnetSessionRequest(BaseModel):
    session_id: str


class TelnetRunCommandsRequest(BaseModel):
    host: str
    port: int = 23
    username: str
    password: str
    commands: list[str]


def _cleanup_stale_sessions():
    cutoff = time.time() - 3600
    stale_ids = []

    with TELNET_SESSIONS_LOCK:
        for session_id, session in TELNET_SESSIONS.items():
            if session.closed or session.last_activity < cutoff:
                stale_ids.append(session_id)

    for session_id in stale_ids:
        _close_telnet_session(session_id)


def _get_telnet_session(session_id: str) -> TelnetSession:
    with TELNET_SESSIONS_LOCK:
        session = TELNET_SESSIONS.get(session_id)

    if not session or session.closed:
        raise HTTPException(status_code=404, detail="Telnet session not found")

    return session


def _parse_telnet_bytes(chunk: bytes) -> tuple[bytes, bytes]:
    cleaned = bytearray()
    responses = bytearray()
    index = 0

    while index < len(chunk):
        current = chunk[index]

        if current != TELNET_IAC:
            cleaned.append(current)
            index += 1
            continue

        if index + 1 >= len(chunk):
            break

        command = chunk[index + 1]

        if command == TELNET_IAC:
            cleaned.append(TELNET_IAC)
            index += 2
            continue

        if command in (TELNET_WILL, TELNET_WONT, TELNET_DO, TELNET_DONT):
            if index + 2 >= len(chunk):
                break

            option = chunk[index + 2]
            if command == TELNET_WILL:
                if option in (TELNET_OPT_BINARY, TELNET_OPT_ECHO, TELNET_OPT_SGA):
                    responses.extend(bytes([TELNET_IAC, TELNET_DO, option]))
                else:
                    responses.extend(bytes([TELNET_IAC, TELNET_DONT, option]))
            elif command == TELNET_DO:
                if option in (TELNET_OPT_BINARY, TELNET_OPT_SGA):
                    responses.extend(bytes([TELNET_IAC, TELNET_WILL, option]))
                else:
                    responses.extend(bytes([TELNET_IAC, TELNET_WONT, option]))
            elif command == TELNET_WONT:
                responses.extend(bytes([TELNET_IAC, TELNET_DONT, option]))
            else:
                responses.extend(bytes([TELNET_IAC, TELNET_WONT, option]))

            index += 3
            continue

        if command == TELNET_SB:
            end_index = chunk.find(bytes([TELNET_IAC, TELNET_SE]), index + 2)
            if end_index == -1:
                break
            index = end_index + 2
            continue

        index += 2

    return bytes(cleaned), bytes(responses)


def _append_output(session: TelnetSession, text: str):
    if not text:
        return

    with session.buffer_lock:
        session.read_buffer.append(text)


def _drain_output(session: TelnetSession) -> str:
    with session.buffer_lock:
        if not session.read_buffer:
            return ""

        data = "".join(session.read_buffer)
        session.read_buffer.clear()
        return data


def _telnet_reader_loop(session: TelnetSession):
    while not session.closed:
        try:
            chunk = session.socket.recv(4096)
        except socket.timeout:
            continue
        except OSError:
            session.closed = True
            break

        if not chunk:
            session.closed = True
            break

        payload, responses = _parse_telnet_bytes(chunk)

        if responses:
            try:
                with session.socket_lock:
                    session.socket.sendall(responses)
            except OSError:
                session.closed = True
                break

        if payload:
            cleaned = payload.decode('utf-8', errors='ignore').replace('\r\0', '')
            _append_output(session, cleaned)

        session.last_activity = time.time()


def _close_telnet_session(session_id: str):
    with TELNET_SESSIONS_LOCK:
        session = TELNET_SESSIONS.pop(session_id, None)

    if not session:
        return

    with session.socket_lock:
        session.closed = True
        try:
            session.socket.shutdown(socket.SHUT_RDWR)
        except OSError:
            pass
        try:
            session.socket.close()
        except OSError:
            pass

@app.get("/")
def read_root():
    return {"status": "Middleware is running"}

@app.post("/ping")
def ping_device(req: PingRequest):
    delay = ping3.ping(req.host, timeout=2)
    if delay is None:
        return {"host": req.host, "online": False, "delay": None}
    return {"host": req.host, "online": True, "delay": delay}


@app.post("/telnet/connect")
def telnet_connect(req: TelnetConnectRequest):
    _cleanup_stale_sessions()

    try:
        sock = socket.create_connection((req.host, req.port), timeout=5)
        sock.settimeout(0.5)
    except OSError as exc:
        raise HTTPException(status_code=502, detail=f"Failed to connect to telnet endpoint: {exc}")

    session = TelnetSession(req.host, req.port, sock)
    session.reader_thread = threading.Thread(target=_telnet_reader_loop, args=(session,), daemon=True)
    session.reader_thread.start()

    with TELNET_SESSIONS_LOCK:
        TELNET_SESSIONS[session.id] = session

    time.sleep(0.3)
    initial_output = _drain_output(session)

    return {
        "session_id": session.id,
        "host": session.host,
        "port": session.port,
        "output": initial_output,
    }


@app.post("/telnet/write")
def telnet_write(req: TelnetInputRequest):
    session = _get_telnet_session(req.session_id)

    try:
        with session.socket_lock:
            session.socket.sendall(req.data.encode("utf-8", errors="ignore"))
            session.last_activity = time.time()
    except OSError as exc:
        session.closed = True
        raise HTTPException(status_code=502, detail=f"Failed to write to telnet session: {exc}")

    time.sleep(0.1)
    return {
        "session_id": session.id,
        "output": _drain_output(session),
        "closed": session.closed,
    }


@app.post("/telnet/read")
def telnet_read(req: TelnetSessionRequest):
    session = _get_telnet_session(req.session_id)

    return {
        "session_id": session.id,
        "output": _drain_output(session),
        "closed": session.closed,
    }


@app.post("/telnet/close")
def telnet_close(req: TelnetSessionRequest):
    _close_telnet_session(req.session_id)
    return {"closed": True}


def _telnet_read_until(sock, patterns, timeout=8):
    """One-shot synchronous read (not the async session machinery above) — used only by
    telnet_run_commands, which does its own login+command sequence in a single request."""
    buffer = b""
    deadline = time.time() + timeout

    while time.time() < deadline:
        try:
            chunk = sock.recv(4096)
        except socket.timeout:
            continue
        except OSError:
            break

        if not chunk:
            break

        cleaned, responses = _parse_telnet_bytes(chunk)
        if responses:
            try:
                sock.sendall(responses)
            except OSError:
                break

        buffer += cleaned

        if any(pattern.encode() in buffer for pattern in patterns):
            break

    return buffer.decode("utf-8", errors="ignore")


@app.post("/telnet/run-commands")
def telnet_run_commands(req: TelnetRunCommandsRequest):
    """Logs in over telnet, runs a batch of show commands, and returns their output.
    Used for automated diagnostic collection (e.g. by the AI incident investigator) where
    there's no interactive user typing credentials, unlike the /telnet/connect session flow."""
    try:
        sock = socket.create_connection((req.host, req.port), timeout=10)
    except OSError as exc:
        raise HTTPException(status_code=502, detail=f"Failed to connect: {exc}")

    sock.settimeout(1)

    try:
        _telnet_read_until(sock, ["Username:", "sername:"])
        sock.sendall((req.username + "\r\n").encode("ascii", errors="ignore"))

        _telnet_read_until(sock, ["Password:", "assword:"])
        sock.sendall((req.password + "\r\n").encode("ascii", errors="ignore"))

        login_result = _telnet_read_until(sock, [">", "#"])
        if ">" not in login_result and "#" not in login_result:
            raise HTTPException(status_code=502, detail="Telnet login did not reach a device prompt.")

        sock.sendall(b"terminal length 0\r\n")
        _telnet_read_until(sock, [">", "#"])

        results = {}
        for command in req.commands:
            sock.sendall((command + "\r\n").encode("ascii", errors="ignore"))
            output = _telnet_read_until(sock, [">", "#"], timeout=15)
            results[command] = output

        return {"results": results}
    finally:
        try:
            sock.shutdown(socket.SHUT_RDWR)
        except OSError:
            pass
        sock.close()

@app.post("/run-command")
def run_cli_command(req: DeviceConfig):
    try:
        # create SSH client and set policy
        client = paramiko.SSHClient()
        client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        
        # open a Transport so we can adjust kex algorithms before authenticating
        transport = paramiko.Transport((req.host, 22))
        opts = transport.get_security_options()
        opts.kex = ("diffie-hellman-group1-sha1",) + opts.kex

        transport.connect(username=req.username, password=req.password)
        
        # once authenticated we can open a channel to run the command
        channel = transport.open_session()
        channel.exec_command(req.command)

        def _to_str(data):
            if isinstance(data, bytes):
                return data.decode('utf-8', errors='ignore')
            return data

        output = _to_str(channel.makefile("r", -1).read())
        error = _to_str(channel.makefile_stderr("r", -1).read())
        
        full_output = output
        if error:
            full_output += "\n--- ERROR ---\n" + error
            
        return {"host": req.host, "command": req.command, "output": full_output.strip()}
    except paramiko.ssh_exception.IncompatiblePeer as e:
        # Fall back to telnet since the router uses SSHv1 (1.5) and Paramiko strictly requires SSHv2 (2.0)
        import telnetlib
        import time
        try:
            tn = telnetlib.Telnet(req.host, 23, timeout=5)
            
            # Read until Username prompt
            tn.read_until(b"Username: ", timeout=3)
            tn.write(req.username.encode('ascii') + b"\n")
            
            # Read until Password prompt
            tn.read_until(b"Password: ", timeout=3)
            tn.write(req.password.encode('ascii') + b"\n")
            
            # Wait for login to complete and prompt to appear
            tn.read_until(b">", timeout=2) # Could be > or #
            
            # If not in enable mode, just send term length 0 anyway, might fail but usually works if priv 15
            tn.write(b"terminal length 0\n")
            tn.read_until(b"#", timeout=2)
            time.sleep(1)
            tn.read_very_eager() # Clear banner and term len 0 output
            
            # Send command
            tn.write(req.command.encode('ascii') + b"\n")
            time.sleep(2)
            
            # Get output until next prompt
            output = tn.read_very_eager().decode('ascii', errors='ignore')
            tn.close()
            
            return {"host": req.host, "command": req.command, "output": output.strip()}
            
        except Exception as telnet_err:
            raise HTTPException(status_code=500, detail=f"SSH failed (Incompatible version), and Telnet fallback also failed: {telnet_err}")
            
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))
    finally:
        try:
            transport.close()
        except Exception:
            pass
        try:
            client.close()
        except Exception:
            pass

@app.post("/run-config")
def run_bulk_config(req: DeviceConfig):
    if not req.commands:
        raise HTTPException(status_code=400, detail="No commands provided")
    
    device = {
        'device_type': req.device_type,
        'host':   req.host,
        'username': req.username,
        'password': req.password,
    }
    try:
        net_connect = ConnectHandler(**device)
        output = net_connect.send_config_set(req.commands)
        net_connect.disconnect()
        return {"host": req.host, "output": output}
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

@app.post("/backup-config")
def backup_config(req: DeviceConfig):
    device = {
        'device_type': req.device_type,
        'host':   req.host,
        'username': req.username,
        'password': req.password,
    }
    try:
        net_connect = ConnectHandler(**device)
        # Assuming cisco ios for default show run
        output = net_connect.send_command("show running-config")
        net_connect.disconnect()
        
        timestamp = datetime.datetime.now().strftime("%Y%m%d_%H%M%S")
        filename = f"{req.host}_{timestamp}_show_run_config.log"
        
        with open(filename, "w") as f:
            f.write(output)
            
        return {"host": req.host, "status": "Success", "filename": filename}
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=8001)
