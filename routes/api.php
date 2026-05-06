<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Host\EventController as HostEventController;
use App\Http\Controllers\Host\RoundController;
use App\Http\Controllers\Donor\EventController as DonorEventController;
use App\Http\Controllers\Donor\BidController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\Donor\PaymentController;


// ── Public ──────────────────────────────────────
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login',    [AuthController::class, 'login']);
Route::get('/events/join/{code}', [DonorEventController::class, 'joinByCode']);

// ── Authenticated ────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    Route::put('/profile', [UserController::class, 'update']);
    Route::put('/profile/password', [UserController::class, 'changePassword']);

    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/user',         [AuthController::class, 'me']);
	Route::post('/upload-avatar', [UserController::class, 'uploadAvatar'])->middleware('auth:sanctum');

    // ── Host routes ──────────────────────────────
    Route::prefix('host')->middleware('host')->group(function () {
        Route::apiResource('events', HostEventController::class);
        Route::post('events/{id}/start',              [HostEventController::class, 'start']);
        Route::post('events/{id}/end',                [HostEventController::class, 'end']);
        Route::get('events/{id}/donors',              [HostEventController::class, 'donors']);
        Route::post('events/{id}/rounds/start',       [RoundController::class, 'start']);
        Route::post('events/{id}/rounds/{rid}/end',   [RoundController::class, 'end']);
        Route::get('events/{id}/rounds',              [RoundController::class, 'index']);
    });

    // ── Donor routes ─────────────────────────────
    Route::prefix('donor')->group(function () {
        Route::get('events',              [DonorEventController::class, 'index']);
        Route::get('events/{id}',         [DonorEventController::class, 'show']);
        Route::get('events/{id}/group',   [DonorEventController::class, 'myGroup']);
        Route::post('events/{id}/bid',    [BidController::class, 'store']);
        Route::post('events/{id}/quit',   [BidController::class, 'quit']);
		Route::post('events/{id}/join',   [DonorEventController::class, 'join']); // ← add this
		Route::get('events/{id}/payment',            [PaymentController::class, 'summary']);
		Route::post('events/{id}/payment/mark-paid', [PaymentController::class, 'markPaid']);
    });
});