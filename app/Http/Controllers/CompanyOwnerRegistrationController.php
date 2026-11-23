<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompanyOwnerRegistrationRequest;
use App\Services\CompanyOwnerRegistrationService;
use Illuminate\Auth\AuthManager;
use Illuminate\Http\RedirectResponse;
use Throwable;

class CompanyOwnerRegistrationController extends Controller
{
    public function __construct(
        private CompanyOwnerRegistrationService $registrationService,
        private AuthManager $auth,
    ) {}

    public function store(StoreCompanyOwnerRegistrationRequest $request): RedirectResponse
    {
        $owner = $this->registrationService->register($request->validated());

        $this->auth->guard()->login($owner);

        try {
            $owner->sendEmailVerificationNotification();
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('verification.notice')
                ->withErrors([
                    'email' => 'Your account was created, but the verification email could not be sent. Please use "Resend verification email".',
                ]);
        }

        return redirect()->route('verification.notice')
            ->with('status', 'verification-link-sent');
    }
}
