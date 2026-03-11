from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
import paramiko
from netmiko import ConnectHandler
import ping3
import datetime

app = FastAPI(title="NetAuto Middleware")

class DeviceConfig(BaseModel):
    device_type: str = 'cisco_ios'
    host: str
    username: str
    password: str
    command: str | None = None
    commands: list[str] | None = None

class PingRequest(BaseModel):
    host: str

@app.get("/")
def read_root():
    return {"status": "Middleware is running"}

@app.post("/ping")
def ping_device(req: PingRequest):
    delay = ping3.ping(req.host, timeout=2)
    if delay is None:
        return {"host": req.host, "online": False, "delay": None}
    return {"host": req.host, "online": True, "delay": delay}

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
