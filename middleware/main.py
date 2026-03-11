from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
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
    device = {
        'device_type': req.device_type,
        'host':   req.host,
        'username': req.username,
        'password': req.password,
    }
    try:
        net_connect = ConnectHandler(**device)
        output = net_connect.send_command(req.command)
        net_connect.disconnect()
        return {"host": req.host, "command": req.command, "output": output}
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

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
    uvicorn.run(app, host="0.0.0.0", port=8000)
