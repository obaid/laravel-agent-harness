<?php

declare(strict_types=1);

use Clutch\Laravel\Http\Controllers\ApprovalController;
use Clutch\Laravel\Http\Controllers\ArtifactController;
use Clutch\Laravel\Http\Controllers\RunController;
use Clutch\Laravel\Http\Controllers\RunEventStreamController;
use Clutch\Laravel\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Agent Harness Routes
|--------------------------------------------------------------------------
|
| Registered only when `clutch.routes.enabled` is true. Every route is
| participant-scoped: a run or session belonging to another participant is not
| reachable through any of them.
|
*/

Route::get('sessions', [SessionController::class, 'index'])->name('sessions.index');
Route::get('sessions/{session}', [SessionController::class, 'show'])->name('sessions.show');

Route::get('runs/{run}', [RunController::class, 'show'])->name('runs.show');
Route::get('runs/{run}/events', RunEventStreamController::class)->name('runs.stream');
Route::get('runs/{run}/events/history', [RunController::class, 'events'])->name('runs.events');
Route::post('runs/{run}/cancel', [RunController::class, 'cancel'])->name('runs.cancel');
Route::post('runs/{run}/retry', [RunController::class, 'retry'])->name('runs.retry');

Route::get('runs/{run}/approvals', [ApprovalController::class, 'index'])->name('approvals.index');
Route::post('runs/{run}/approvals/{approval}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');
Route::post('runs/{run}/approvals/{approval}/reject', [ApprovalController::class, 'reject'])->name('approvals.reject');

Route::get('artifacts/{artifact}', ArtifactController::class)->name('artifacts.show');
