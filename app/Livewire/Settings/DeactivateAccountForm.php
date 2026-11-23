<?php

namespace App\Livewire\Settings;

use App\Concerns\PasswordValidationRules;
use App\Livewire\Actions\Logout;
use App\Services\AccountLifecycleService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class DeactivateAccountForm extends Component
{
    use PasswordValidationRules;

    public string $password = '';

    public function deactivate(Logout $logout, AccountLifecycleService $service): void
    {
        $this->validate([
            'password' => $this->currentPasswordRules(),
        ]);

        try {
            $service->deactivateOwnAccount(Auth::user());
        } catch (ValidationException $exception) {
            $this->addError('password', $exception->validator->errors()->first('account'));

            return;
        }

        $this->reset('password');
        $logout();

        session()->flash('status', __('Your account has been deactivated. You can request a reactivation link from the sign-in page.'));
        $this->redirect(route('login'), navigate: true);
    }
}
