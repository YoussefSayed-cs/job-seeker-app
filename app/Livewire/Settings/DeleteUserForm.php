<?php

namespace App\Livewire\Settings;

use App\Concerns\PasswordValidationRules;
use App\Livewire\Actions\Logout;
use App\Services\AccountLifecycleService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Shagalni\Shared\Notifications\AccountDeletionRequested;

class DeleteUserForm extends Component
{
    use PasswordValidationRules;

    public string $password = '';

    public function deleteUser(Logout $logout, AccountLifecycleService $service): void
    {
        $this->validate([
            'password' => $this->currentPasswordRules(),
        ]);

        try {
            $user = $service->requestDeletion(Auth::user());
        } catch (ValidationException $exception) {
            $this->addError('password', $exception->validator->errors()->first('account'));

            return;
        }

        $user->notify(new AccountDeletionRequested);
        $this->reset('password');
        $logout();

        session()->flash('status', __('Your account deletion request was received. You can cancel it using the link sent to your email within 30 days.'));
        $this->redirect(route('login'), navigate: true);
    }
}
