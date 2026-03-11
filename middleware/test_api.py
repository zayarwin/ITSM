import urllib.request
import json
import sys

req = urllib.request.Request(
    'http://127.0.0.1:8001/run-command',
    data=json.dumps({
        'host': '192.168.108.148',
        'username': 'admin',
        'password': 'password', # Try with wrong or correct password depending on config
        'command': 'show version',
        'device_type': 'cisco_ios'
    }).encode('utf-8'),
    headers={'Content-Type': 'application/json'}
)

try:
    response = urllib.request.urlopen(req)
    print("SUCCESS:")
    print(response.read().decode('utf-8'))
except urllib.error.HTTPError as e:
    print(f"HTTP Error {e.code}:")
    print(e.read().decode('utf-8'))
except Exception as e:
    print(f"Other Error: {e}")
