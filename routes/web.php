<?php

use App\Http\Controllers\AccountDeletionController;
use App\Http\Controllers\AccountReactivationController;
use App\Http\Controllers\CompanyOwnerRegistrationController;
use App\Http\Controllers\CompanyProfileController;
use App\Http\Controllers\JobSearchController;
use App\Http\Controllers\RejectedCompanyRegistrationController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware('guest')->group(function () {
    Route::post('/company-owner/register', [CompanyOwnerRegistrationController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('company-owner.register.store');

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

    Route::get('/company-registration/rejected', [RejectedCompanyRegistrationController::class, 'show'])
        ->name('company-registration.rejected.show');

    Route::patch('/company-registration/rejected', [RejectedCompanyRegistrationController::class, 'update'])
        ->middleware('throttle:5,1')
        ->name('company-registration.rejected.update');

    Route::get('/company-profile', [CompanyProfileController::class, 'show'])
        ->name('company-profile.show');

    Route::patch('/company-profile', [CompanyProfileController::class, 'update'])
        ->middleware('throttle:10,1')
        ->name('company-profile.update');

    Route::get('/jobs/search', [JobSearchController::class, 'index'])
        ->name('jobs.search');
});

require __DIR__.'/settings.php';
