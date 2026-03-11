<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [\App\Http\Controllers\AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [\App\Http\Controllers\AuthController::class, 'logout']);
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Protect Device handling endpoints so you have to be logged in
    Route::apiResource('devices', \App\Http\Controllers\DeviceController::class);
    Route::post('/devices/{id}/run-command', [\App\Http\Controllers\DeviceController::class, 'runCommand']);
    Route::get('/devices/{id}/ping', [\App\Http\Controllers\DeviceController::class, 'ping']);

    // Admin-only User management endpoints
    Route::apiResource('users', \App\Http\Controllers\UserController::class);
    
    // Change Request endpoints
    Route::get('/change-requests', [\App\Http\Controllers\ChangeRequestController::class, 'index']);
    Route::post('/change-requests', [\App\Http\Controllers\ChangeRequestController::class, 'store']);
    Route::put('/change-requests/{id}/status', [\App\Http\Controllers\ChangeRequestController::class, 'updateStatus']);
    Route::get('/change-requests/{id}/attachment', [\App\Http\Controllers\ChangeRequestController::class, 'downloadAttachment']);
});
