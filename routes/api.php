<?php

use App\Http\Controllers\Api\MockInsurerCallbackController;
use App\Http\Controllers\Api\PolicyRequestController;
use Illuminate\Support\Facades\Route;

// Policy Requests API
Route::post('/policy-requests', [PolicyRequestController::class, 'store']);
Route::get('/policy-requests', [PolicyRequestController::class, 'index']);
Route::get('/policy-requests/{id}', [PolicyRequestController::class, 'show']);
Route::post('/policy-requests/{id}/submit', [PolicyRequestController::class, 'submit']);
Route::post('/policy-requests/{id}/reconcile', [PolicyRequestController::class, 'reconcile']);

// Mock Insurer Callback
Route::post('/mock-insurer/callback', [MockInsurerCallbackController::class, 'handle']);
Route::get('/mock-insurer/status/{providerReference}', [MockInsurerCallbackController::class, 'status']);

// Health check
Route::get('/health', fn() => response()->json(['status' => 'ok', 'timestamp' => now()->toIso8601String()]));

