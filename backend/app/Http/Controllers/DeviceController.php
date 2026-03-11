<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class DeviceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return response()->json(\App\Models\Device::all());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'hostname' => 'required|string|max:255|unique:devices',
            'ip_address' => 'required|ip',
            'os_version' => 'nullable|string',
            'location' => 'nullable|string',
            'eol_date' => 'nullable|date',
            'model' => 'nullable|string',
            'username' => 'nullable|string',
            'password' => 'nullable|string',
            'device_type' => 'required|string',
        ]);

        $device = \App\Models\Device::create($validated);
        return response()->json($device, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $device = \App\Models\Device::findOrFail($id);
        return response()->json($device);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $device = \App\Models\Device::findOrFail($id);
        
        $validated = $request->validate([
            'hostname' => 'sometimes|required|string|max:255|unique:devices,hostname,'.$device->id,
            'ip_address' => 'sometimes|required|ip',
            'os_version' => 'nullable|string',
            'location' => 'nullable|string',
            'eol_date' => 'nullable|date',
            'model' => 'nullable|string',
            'username' => 'nullable|string',
            'password' => 'nullable|string',
            'device_type' => 'sometimes|required|string',
        ]);
        
        // If password is blank/null on edit, keep the old one
        if (empty($validated['password'])) {
            unset($validated['password']);
        }
        
        $device->update($validated);
        return response()->json($device);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $device = \App\Models\Device::findOrFail($id);
        $device->delete();
        return response()->json(null, 204);
    }

    /**
     * Run a CLI command on the device via the Python middleware.
     */
    public function runCommand(Request $request, string $id)
    {
        $device = \App\Models\Device::findOrFail($id);
        
        $validated = $request->validate([
            'command' => 'required|string',
        ]);

        try {
            $response = \Illuminate\Support\Facades\Http::post('http://127.0.0.1:8001/run-command', [
                'device_type' => $device->device_type,
                'host'        => $device->ip_address,
                'username'    => $device->username,
                'password'    => $device->password,
                'command'     => $validated['command'],
            ]);

            if ($response->successful()) {
                return $response->json();
            } else {
                return response()->json([
                    'error' => 'Middleware error',
                    'details' => $response->json()
                ], $response->status());
            }
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to connect to middleware', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Ping the device to check online status.
     */
    public function ping(string $id)
    {
        $device = \App\Models\Device::findOrFail($id);
        $ip = escapeshellarg($device->ip_address);
        
        // ping -n 1 sends 1 request, -w 1000 waits 1 second for response (Windows)
        exec("ping -n 1 -w 1000 {$ip}", $output, $status);
        
        if ($status === 0) {
            return response()->json(['status' => 'online']);
        } else {
            return response()->json(['status' => 'offline']);
        }
    }
}
