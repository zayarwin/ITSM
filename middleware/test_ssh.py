import paramiko
import sys
import json
import argparse

parser = argparse.ArgumentParser(description='Run a command on a router via SSH')
parser.add_argument('command', nargs='?', default='show version',
                    help='command to execute (default: show run)')
parser.add_argument('--json', '-j', action='store_true',
                    help='print results in JSON format')
args = parser.parse_args()
COMMAND = args.command
JSON = args.json

HOST = '192.168.108.148'
USERNAME = 'admin'
PASSWORD = 'cisco'

client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())

try:
    transport = paramiko.Transport((HOST, 22))
    opts = transport.get_security_options()
    opts.kex = ("diffie-hellman-group1-sha1",) + opts.kex

    transport.connect(username=USERNAME, password=PASSWORD)
    print(f"Connected to {HOST} via custom Transport")

    channel = transport.open_session()
    channel.exec_command(COMMAND)

    def _to_str(data):
        if isinstance(data, bytes):
            return data.decode('utf-8', errors='ignore')
        return data

    output = _to_str(channel.makefile("r", -1).read())
    error = _to_str(channel.makefile_stderr("r", -1).read())

    if output or error:
        if JSON:
            result = {'command': COMMAND, 'output': output}
            if error:
                result['error'] = error
            print(json.dumps(result))
        else:
            if output:
                print("\n--- OUTPUT ---")
                print(output)
            if error:
                print("\n--- ERROR ---")
                print(error, file=sys.stderr)

except Exception as e:
    print(f"SSH connection failed: {e}", file=sys.stderr)
finally:
    try:
        transport.close()
    except Exception:
        pass
    client.close()
