<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class DeviceController extends Controller
{
    // Explicit, so it's visible in one place rather than implied by "no check exists":
    // admin, engineer, and manager all have identical full access to device inventory.
    private const ROLES_ALLOWED_TO_MANAGE_DEVICES = ['admin', 'engineer', 'manager'];

    private function denyIfRoleNotAllowed(Request $request): ?\Illuminate\Http\JsonResponse
    {
        if (! in_array($request->user()->role, self::ROLES_ALLOWED_TO_MANAGE_DEVICES, true)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return null;
    }

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
        if ($response = $this->denyIfRoleNotAllowed($request)) {
            return $response;
        }

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
        if ($response = $this->denyIfRoleNotAllowed($request)) {
            return $response;
        }

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
    public function destroy(Request $request, string $id)
    {
        if ($response = $this->denyIfRoleNotAllowed($request)) {
            return $response;
        }

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
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        try {
            $middlewareUrl = rtrim(env('MIDDLEWARE_URL', 'http://127.0.0.1:8001'), '/');
            $response = \Illuminate\Support\Facades\Http::post("{$middlewareUrl}/run-command", [
                'device_type' => $device->device_type,
                'host'        => $device->ip_address,
                'username'    => $validated['username'],
                'password'    => $validated['password'],
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

    public function connectTelnet(Request $request, string $id)
    {
        $device = \App\Models\Device::findOrFail($id);

        $validated = $request->validate([
            'port' => 'nullable|integer|min:1|max:65535',
        ]);

        try {
            $middlewareUrl = rtrim(env('MIDDLEWARE_URL', 'http://127.0.0.1:8001'), '/');
            $response = \Illuminate\Support\Facades\Http::post("{$middlewareUrl}/telnet/connect", [
                'host' => $device->ip_address,
                'port' => $validated['port'] ?? 23,
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            return response()->json([
                'error' => 'Middleware error',
                'details' => $response->json(),
            ], $response->status());
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to connect to middleware', 'message' => $e->getMessage()], 500);
        }
    }

    public function writeTelnet(Request $request, string $id)
    {
        \App\Models\Device::findOrFail($id);

        $validated = $request->validate([
            'session_id' => 'required|string',
            // Not "required": keystrokes like Enter are pure whitespace ("\r\n"),
            // and Laravel's "required" rule trims the value and rejects whitespace-only strings.
            'data' => 'present|string',
        ]);

        try {
            $middlewareUrl = rtrim(env('MIDDLEWARE_URL', 'http://127.0.0.1:8001'), '/');
            $response = \Illuminate\Support\Facades\Http::post("{$middlewareUrl}/telnet/write", $validated);

            if ($response->successful()) {
                return $response->json();
            }

            return response()->json([
                'error' => 'Middleware error',
                'details' => $response->json(),
            ], $response->status());
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to connect to middleware', 'message' => $e->getMessage()], 500);
        }
    }

    public function readTelnet(Request $request, string $id)
    {
        \App\Models\Device::findOrFail($id);

        $validated = $request->validate([
            'session_id' => 'required|string',
        ]);

        try {
            $middlewareUrl = rtrim(env('MIDDLEWARE_URL', 'http://127.0.0.1:8001'), '/');
            $response = \Illuminate\Support\Facades\Http::post("{$middlewareUrl}/telnet/read", $validated);

            if ($response->successful()) {
                return $response->json();
            }

            return response()->json([
                'error' => 'Middleware error',
                'details' => $response->json(),
            ], $response->status());
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to connect to middleware', 'message' => $e->getMessage()], 500);
        }
    }

    public function closeTelnet(Request $request, string $id)
    {
        \App\Models\Device::findOrFail($id);

        $validated = $request->validate([
            'session_id' => 'required|string',
        ]);

        try {
            $middlewareUrl = rtrim(env('MIDDLEWARE_URL', 'http://127.0.0.1:8001'), '/');
            $response = \Illuminate\Support\Facades\Http::post("{$middlewareUrl}/telnet/close", $validated);

            if ($response->successful()) {
                return $response->json();
            }

            return response()->json([
                'error' => 'Middleware error',
                'details' => $response->json(),
            ], $response->status());
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to connect to middleware', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Ping the device to check online status.
     * Delegated to the Python middleware which has NET_RAW capability for ICMP.
     */
    public function ping(string $id)
    {
        $device = \App\Models\Device::findOrFail($id);
        $middlewareUrl = rtrim(env('MIDDLEWARE_URL', 'http://127.0.0.1:8001'), '/');

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(5)
                ->post("{$middlewareUrl}/ping", ['host' => $device->ip_address]);

            if ($response->successful()) {
                $online = $response->json('online', false);
                return response()->json(['status' => $online ? 'online' : 'offline']);
            }
        } catch (\Exception $e) {
            // middleware unreachable — fall through to offline
        }

        return response()->json(['status' => 'offline']);
    }

    /**
     * Ping every device in one call. The browser has a per-origin connection limit
     * (~6 in Chrome); pinging N devices as N separate browser requests queues them up and
     * can delay unrelated requests (like an Add/Edit/Delete save) behind the ping burst for
     * several seconds. Fanning the pings out server-side with Http::pool() isn't subject to
     * that limit, so the browser only ever makes one request regardless of device count.
     */
    public function pingAll()
    {
        $devices = \App\Models\Device::all(['id', 'ip_address']);
        $middlewareUrl = rtrim(env('MIDDLEWARE_URL', 'http://127.0.0.1:8001'), '/');

        $responses = \Illuminate\Support\Facades\Http::pool(fn ($pool) => $devices->map(
            fn ($device) => $pool->as((string) $device->id)
                ->timeout(5)
                ->post("{$middlewareUrl}/ping", ['host' => $device->ip_address])
        )->all());

        $statuses = [];
        foreach ($devices as $device) {
            $response = $responses[(string) $device->id] ?? null;
            $online = $response instanceof \Illuminate\Http\Client\Response
                && $response->successful()
                && $response->json('online', false);
            $statuses[$device->id] = $online ? 'online' : 'offline';
        }

        return response()->json($statuses);
    }
}
