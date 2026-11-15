<?php

namespace App\Livewire\Settings;

use Livewire\Component;

class DeleteUserForm extends Component
{
    /**
     * Block the legacy destructive endpoint until lifecycle cancellation is integrated.
     */
    public function deleteUser(): never
    {
        abort(403, __('Account deletion is unavailable through this portal.'));
    }
}
