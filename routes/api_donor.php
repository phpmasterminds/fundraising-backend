<?php
// routes/api.php — donor routes (add inside your existing api.php)
// Assumes Sanctum auth middleware is applied via prefix group

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Donor\EventController;
use App\Http\Controllers\Donor\BidController;
use App\Http\Controllers\Donor\PaymentController;

Route::middleware('auth:sanctum')->prefix('donor')->group(function () {

    // Event list (upcoming / finished tabs)
    Route::get('/events',                      [EventController::class, 'index']);

    // Event detail + my participation
    Route::get('/events/{event}',              [EventController::class, 'show']);

    // Current round state for this donor
    Route::get('/events/{event}/round',        [EventController::class, 'currentRound']);

    // Specific closed round result
    Route::get('/events/{event}/rounds/{round}', [EventController::class, 'roundResult']);

    // Submit / update bid
    Route::post('/events/{event}/bids',        [BidController::class, 'store']);

    // Payment summary
    Route::get('/events/{event}/payment',      [PaymentController::class, 'summary']);

    // Mark as paid offline
    Route::post('/events/{event}/payment/mark-paid', [PaymentController::class, 'markPaid']);
});