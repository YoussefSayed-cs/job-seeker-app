<?php

namespace App\Http\Controllers;

use App\Services\AccountLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Shagalni\Shared\Enums\AccountStatus;
use Shagalni\Shared\Models\User;

class AccountDeletionController extends Controller
{
    public function __construct(private AccountLifecycleService $service) {}

    public function cancel(Request $request, User $user): RedirectResponse
    {
        try {
            $user = $this->service->cancelDeletion($user, (int) $request->query('requested'));
        } catch (ValidationException $exception) {
            return redirect()
                ->route('login')
                ->with('status', $exception->validator->errors()->first('account'));
        }

        $message = match ($user->status) {
            AccountStatus::Active => 'Your account deletion request has been cancelled. You can sign in now.',
            AccountStatus::Deactivated => 'Your deletion request has been cancelled. Your account is still deactivated; request a reactivation link to sign in.',
            AccountStatus::Suspended => 'Your deletion request has been cancelled. Your account remains suspended and requires an administrator to restore access.',
        };

        return redirect()->route('login')->with('status', $message);
    }
}
