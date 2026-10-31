<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BatchController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\PinController;
use App\Http\Controllers\Api\PinImageController;
use App\Http\Controllers\Api\PinterestController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\QueueController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\TemplateController;
use App\Http\Controllers\Api\UrlController;
use Illuminate\Support\Facades\Route;

// Session (cookie) authentication for the first-party SPA via Sanctum's stateful API.
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('me', [AuthController::class, 'me'])->middleware('auth:sanctum');
});

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('dashboard', DashboardController::class);

    // URL submission & extraction
    Route::post('urls/analyze', [UrlController::class, 'analyze'])->middleware('throttle:generation');
    Route::post('urls/bulk', [UrlController::class, 'bulk'])->middleware('throttle:generation');
    Route::get('urls', [UrlController::class, 'index']);
    Route::get('urls/{submission}', [UrlController::class, 'show']);

    // Bulk batches
    Route::get('batches', [BatchController::class, 'index']);
    Route::get('batches/{batch}', [BatchController::class, 'show']);
    Route::post('batches/{batch}/pause', [BatchController::class, 'pause']);
    Route::post('batches/{batch}/resume', [BatchController::class, 'resume']);
    Route::post('batches/{batch}/cancel', [BatchController::class, 'cancel']);
    Route::post('batches/{batch}/retry', [BatchController::class, 'retry']);
    Route::get('batches/{batch}/export', [BatchController::class, 'export']);

    // Pins
    Route::get('pins/export', [PinController::class, 'export']);
    Route::post('pins/generate', [PinController::class, 'generate'])->middleware('throttle:generation');
    Route::get('pins', [PinController::class, 'index']);
    Route::get('pins/{pin}', [PinController::class, 'show']);
    Route::patch('pins/{pin}', [PinController::class, 'update']);
    Route::delete('pins/{pin}', [PinController::class, 'destroy']);
    Route::post('pins/{pin}/regenerate', [PinController::class, 'regenerate'])->middleware('throttle:generation');
    Route::post('pins/{pin}/approve', [PinController::class, 'approve']);
    Route::post('pins/{pin}/publish', [PinController::class, 'publish'])->middleware('throttle:publishing');
    Route::post('pins/{pin}/schedule', [PinController::class, 'schedule']);
    Route::delete('pins/{pin}/schedule', [PinController::class, 'unschedule']);

    // Pin images
    Route::post('pins/{pin}/images/upload', [PinImageController::class, 'upload'])->middleware('throttle:generation');
    Route::post('pins/{pin}/images/import', [PinImageController::class, 'import'])->middleware('throttle:generation');
    Route::post('pins/{pin}/images/generate', [PinImageController::class, 'generate'])->middleware('throttle:generation');
    Route::get('pin-images/{image}/file', [PinImageController::class, 'file'])->withoutMiddleware('throttle:api');
    Route::post('pin-images/{image}/select', [PinImageController::class, 'select']);
    Route::patch('pin-images/{image}', [PinImageController::class, 'update']);
    Route::delete('pin-images/{image}', [PinImageController::class, 'destroy']);

    // Publishing queue
    Route::get('publishing-queue', [QueueController::class, 'index']);
    Route::get('publishing-queue/calendar', [QueueController::class, 'calendar']);
    Route::post('publishing-queue/automation', [QueueController::class, 'toggleAutomation']);
    Route::post('publishing-queue/pins/{pin}/retry', [QueueController::class, 'retry'])->middleware('throttle:publishing');
    Route::post('publishing-queue/jobs/{job}/cancel', [QueueController::class, 'cancelJob']);

    // Pinterest accounts & boards
    Route::post('pinterest/connect', [PinterestController::class, 'connect']);
    Route::get('pinterest/accounts', [PinterestController::class, 'accounts']);
    Route::delete('pinterest/accounts/{account}', [PinterestController::class, 'disconnect']);
    Route::post('pinterest/accounts/{account}/refresh', [PinterestController::class, 'refreshToken']);
    Route::get('pinterest/boards', [PinterestController::class, 'boards']);
    Route::post('pinterest/boards', [PinterestController::class, 'createBoard']);
    Route::post('pinterest/default-board', [PinterestController::class, 'setDefault']);

    // Settings
    Route::get('settings/meta', [SettingsController::class, 'meta']);
    Route::get('settings/ai', [SettingsController::class, 'ai']);
    Route::put('settings/ai', [SettingsController::class, 'updateAi']);
    Route::get('settings/preferences', [SettingsController::class, 'preferences']);
    Route::put('settings/preferences', [SettingsController::class, 'updatePreferences']);
    Route::put('settings/profile', [AuthController::class, 'updateProfile']);
    Route::put('settings/password', [AuthController::class, 'updatePassword']);

    Route::apiResource('templates', TemplateController::class)->except('show');
    Route::apiResource('projects', ProjectController::class)->except('show');

    // Reports & logs
    Route::get('reports/summary', [ReportController::class, 'summary']);
    Route::get('reports/logs', [ReportController::class, 'logs']);
    Route::get('admin/failed-jobs', [ReportController::class, 'failedJobs']);
    Route::post('admin/failed-jobs/{uuid}/retry', [ReportController::class, 'retryFailedJob']);
    Route::delete('admin/failed-jobs/{uuid}', [ReportController::class, 'forgetFailedJob']);
});
