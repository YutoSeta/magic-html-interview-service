<?php

use App\Http\Controllers\Api\V1\InterviewController;
use App\Http\Controllers\CapabilityController;
use Illuminate\Support\Facades\Route;

Route::get('/', CapabilityController::class);
Route::get('/__verify', [CapabilityController::class, 'verify']);

Route::middleware(['service', 'throttle:interview-writes'])->group(function (): void {
    Route::post('/v1/interviews', [InterviewController::class, 'store']);
    Route::post('/v1/interviews/import', [InterviewController::class, 'import']);
    Route::post('/v1/interviews/{interview}/messages', [InterviewController::class, 'answer']);
    Route::delete('/v1/interviews/{interview}', [InterviewController::class, 'destroy']);
});
Route::middleware(['service', 'throttle:interview-reads'])
    ->get('/v1/interviews/{interview}', [InterviewController::class, 'show']);

Route::pattern('interview', '[0-9a-fA-F-]{36}');
