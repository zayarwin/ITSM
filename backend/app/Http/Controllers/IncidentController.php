<?php

namespace App\Http\Controllers;

use App\Jobs\InvestigateIncident;
use App\Models\Device;
use App\Models\Incident;
use Illuminate\Http\Request;

class IncidentController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            Incident::with('device:id,hostname,ip_address,location')
                ->orderByDesc('detected_at')
                ->limit(200)
                ->get()
        );
    }

    public function show(Request $request, string $id)
    {
        return response()->json(
            Incident::with('device:id,hostname,ip_address,location')->findOrFail($id)
        );
    }

    /**
     * Called by the Python middleware's syslog listener when it detects an operationally
     * significant message (any facility — OSPF, BGP, interface, hardware, etc., not just
     * routing protocols). Authenticated with a shared secret (server-to-server, not a
     * logged-in user) rather than Sanctum, since the middleware has no user session.
     */
    public function ingestSyslogEvent(Request $request)
    {
        $expectedSecret = config('services.middleware.internal_secret');

        if (empty($expectedSecret) || $request->header('X-Internal-Secret') !== $expectedSecret) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $validated = $request->validate([
            'device_ip' => 'required|ip',
            'facility' => 'required|string',
            'severity' => 'nullable|integer|min:0|max:7',
            'mnemonic' => 'nullable|string',
            'previous_state' => 'nullable|string',
            'new_state' => 'nullable|string',
            'raw_message' => 'required|string',
        ]);

        $device = Device::where('ip_address', $validated['device_ip'])->first();

        if (! $device) {
            return response()->json(['message' => 'No device found for that IP; event ignored.'], 200);
        }

        $incident = Incident::create([
            'device_id' => $device->id,
            'facility' => $validated['facility'],
            'severity' => $validated['severity'] ?? null,
            'mnemonic' => $validated['mnemonic'] ?? null,
            'previous_state' => $validated['previous_state'] ?? null,
            'new_state' => $validated['new_state'] ?? null,
            'raw_message' => $validated['raw_message'],
            'status' => 'detected',
            'detected_at' => now(),
        ]);

        InvestigateIncident::dispatch($incident->id);

        return response()->json($incident, 201);
    }
}
