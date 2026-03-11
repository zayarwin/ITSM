import urllib.request
import json
import urllib.error

API_URL = "http://127.0.0.1:8000/api/devices"

def make_req(method, url, data=None):
    req = urllib.request.Request(url, method=method)
    if data:
        req.data = json.dumps(data).encode('utf-8')
        req.add_header('Content-Type', 'application/json')
    req.add_header('Accept', 'application/json')
    try:
        res = urllib.request.urlopen(req)
        raw = res.read().decode('utf-8')
        print("RAW RESPONSE:", raw)
        return json.loads(raw) if raw else None
    except urllib.error.HTTPError as e:
        print(f"HTTP {e.code}: {e.read().decode('utf-8')}")
        return None

# 1. Create Device
print("--- Create ---")
created = make_req("POST", API_URL, {
    "hostname": "Test-RTR",
    "ip_address": "10.0.0.100",
    "username": "apiusr",
    "password": "apipassword123",
    "device_type": "cisco_ios"
})
print("Create resp:", created)
device_id = created['id'] if created else None

if device_id:
    # 2. Get Device (Ensure password is not in response)
    print("\n--- Get ---")
    fetched = make_req("GET", f"{API_URL}/{device_id}")
    print("Get resp:", fetched)
    if 'password' in fetched:
        print("FAIL: Password leaked in GET!")
    else:
        print("SUCCESS: Password hidden.")

    # 3. Update without password
    print("\n--- Update (No Password) ---")
    updated = make_req("PUT", f"{API_URL}/{device_id}", {
        "hostname": "Test-RTR-Edited",
        "ip_address": "10.0.0.100",
        "username": "apiusr",
        "password": "", # blank
        "device_type": "cisco_ios"
    })
    print("Update resp:", updated)

    # 4. Clean up
    print("\n--- Delete ---")
    make_req("DELETE", f"{API_URL}/{device_id}")
    print("Cleanup complete.")
