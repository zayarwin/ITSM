<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [\App\Http\Controllers\AuthController::class, 'login']);

// Server-to-server callback from the Python middleware's syslog listener.
// Authenticated with a shared secret (checked in the controller), not Sanctum.
Route::post('/internal/syslog-event', [\App\Http\Controllers\IncidentController::class, 'ingestSyslogEvent']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [\App\Http\Controllers\AuthController::class, 'logout']);
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Protect Device handling endpoints so you have to be logged in
    // Registered before apiResource's GET /devices/{device} so "ping-all" isn't swallowed
    // by the resource's {device} wildcard and treated as a device ID.
    Route::get('/devices/ping-all', [\App\Http\Controllers\DeviceController::class, 'pingAll']);
    Route::apiResource('devices', \App\Http\Controllers\DeviceController::class);
    Route::post('/devices/{id}/run-command', [\App\Http\Controllers\DeviceController::class, 'runCommand']);
    Route::get('/devices/{id}/ping', [\App\Http\Controllers\DeviceController::class, 'ping']);
    Route::post('/devices/{id}/telnet/connect', [\App\Http\Controllers\DeviceController::class, 'connectTelnet']);
    Route::post('/devices/{id}/telnet/write', [\App\Http\Controllers\DeviceController::class, 'writeTelnet']);
    Route::post('/devices/{id}/telnet/read', [\App\Http\Controllers\DeviceController::class, 'readTelnet']);
    Route::post('/devices/{id}/telnet/close', [\App\Http\Controllers\DeviceController::class, 'closeTelnet']);

    // Admin-only User management endpoints
    Route::apiResource('users', \App\Http\Controllers\UserController::class);
    
    // Change Request endpoints
    Route::get('/change-requests', [\App\Http\Controllers\ChangeRequestController::class, 'index']);
    Route::post('/change-requests', [\App\Http\Controllers\ChangeRequestController::class, 'store']);
    Route::put('/change-requests/{id}/status', [\App\Http\Controllers\ChangeRequestController::class, 'updateStatus']);
    Route::get('/change-requests/{id}/attachment', [\App\Http\Controllers\ChangeRequestController::class, 'downloadAttachment']);

    // AI-investigated incidents (OSPF/BGP state changes detected via syslog)
    Route::get('/incidents', [\App\Http\Controllers\IncidentController::class, 'index']);
    Route::get('/incidents/{id}', [\App\Http\Controllers\IncidentController::class, 'show']);
});
