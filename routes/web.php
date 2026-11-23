<?php

use App\Http\Controllers\AccountDeletionController;
use App\Http\Controllers\AccountReactivationController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/account-reactivation', [AccountReactivationController::class, 'create'])
        ->name('account-reactivation.create');

    Route::post('/account-reactivation', [AccountReactivationController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('account-reactivation.store');
});

Route::get('/account-reactivation/{user}/confirm', [AccountReactivationController::class, 'confirm'])
    ->middleware('signed')
    ->name('account-reactivation.confirm');

Route::get('/account-deletion/{user}/cancel', [AccountDeletionController::class, 'cancel'])
    ->middleware('signed')
    ->name('account-deletion.cancel');

Route::middleware(['auth'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
