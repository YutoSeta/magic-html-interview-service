<?php

use App\Http\Controllers\Api\V1\IntakeSessionController;
use App\Http\Controllers\Api\V1\IntakeTemplateController;
use App\Http\Controllers\Api\V1\InterviewController;
use App\Http\Controllers\CapabilityController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/', CapabilityController::class);
Route::get('/__verify', [CapabilityController::class, 'verify']);
Route::get('/health', HealthController::class)->name('health');

Route::pattern('interview', '[0-9a-fA-F-]{36}');
Route::pattern('template', '[a-z0-9][a-z0-9_-]{0,63}');

Route::middleware(['service', 'throttle:interview-writes'])->group(function (): void {
    Route::post('/v1/interviews', [InterviewController::class, 'store']);
    Route::post('/v1/interviews/import', [InterviewController::class, 'import']);
    Route::post('/v1/interviews/{interview}/messages', [InterviewController::class, 'answer']);
    Route::delete('/v1/interviews/{interview}', [InterviewController::class, 'destroy']);
    Route::put('/v1/intake-templates/{template}', [IntakeTemplateController::class, 'upsert']);
    Route::post('/v1/intake-templates/{template}/sessions', [IntakeSessionController::class, 'store']);
});
Route::middleware(['service', 'throttle:interview-reads'])->group(function (): void {
    Route::get('/v1/interviews/{interview}', [InterviewController::class, 'show']);
    Route::get('/v1/intake-sessions/{interview}', [IntakeSessionController::class, 'show']);
});
