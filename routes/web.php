<?php

use App\Http\Controllers\AccountDeletionController;
use App\Http\Controllers\AccountReactivationController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\CompanyOwnerRegistrationController;
use App\Http\Controllers\CompanyProfileController;
use App\Http\Controllers\JobSearchController;
use App\Http\Controllers\RejectedCompanyRegistrationController;
use App\Http\Controllers\ResumeController;
use App\Http\Controllers\SavedJobsController;
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

    Route::get('/resume', [ResumeController::class, 'show'])->name('resume.show');
    Route::post('/resume', [ResumeController::class, 'store'])->name('resume.store');
    Route::get('/resume/download', [ResumeController::class, 'download'])->name('resume.download');

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

    Route::get('/jobs/{job}', [JobSearchController::class, 'show'])
        ->name('jobs.show');

    Route::post('/jobs/{job}/apply', [ApplicationController::class, 'store'])
        ->name('jobs.apply');

    Route::get('/saved-jobs', [SavedJobsController::class, 'index'])
        ->name('saved-jobs.index');

    Route::post('/saved-jobs', [SavedJobsController::class, 'store'])
        ->name('saved-jobs.store');

    Route::get('/saved-jobs/{savedJob}', [SavedJobsController::class, 'show'])
        ->name('saved-jobs.show');

    Route::delete('/saved-jobs/{savedJob}', [SavedJobsController::class, 'destroy'])
        ->name('saved-jobs.destroy');
});

require __DIR__.'/settings.php';
