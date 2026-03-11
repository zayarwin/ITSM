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
        ]);
        
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
}
