<?php

namespace App\Policies;

use Shagalni\Shared\Enums\UserRole;
use Shagalni\Shared\Models\User;

class JobSearchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::JobSeeker;
    }
}
