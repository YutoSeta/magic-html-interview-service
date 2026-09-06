<?php

use Illuminate\Support\Facades\Route;
use Yutoseta\InterviewEngine\Http\Controllers\InterviewController as EngineInterviewController;
use Yutoseta\InterviewEngine\Http\Controllers\ScriptedController;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('i')->name('interviews.')->middleware('throttle:120,1')->group(function (): void {
    Route::get('/{interview:uuid}', [EngineInterviewController::class, 'show'])
        ->middleware('intake.grant')
        ->name('show');

    Route::prefix('{interview:uuid}')->middleware('intake.access')->group(function (): void {
        Route::get('/progress', [ScriptedController::class, 'progress'])->name('progress');
        Route::post('/answer', [ScriptedController::class, 'answer'])
            ->middleware('intake.answers')
            ->name('answer');
        Route::post('/confirm', [EngineInterviewController::class, 'confirm'])->name('confirm');
        Route::post('/closing', [EngineInterviewController::class, 'closing'])->name('closing');
        Route::post('/ended', [EngineInterviewController::class, 'ended'])->name('ended');
    });
});
