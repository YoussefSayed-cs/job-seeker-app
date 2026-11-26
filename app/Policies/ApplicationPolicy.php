<?php

namespace App\Policies;

use Shagalni\Shared\Enums\AccountStatus;
use Shagalni\Shared\Enums\UserRole;
use Shagalni\Shared\Models\JobVacancy;
use Shagalni\Shared\Models\User;

class ApplicationPolicy
{
    public function create(User $user, JobVacancy $job): bool
    {
        return $user->role === UserRole::JobSeeker
            && $user->status === AccountStatus::Active
            && $user->deletion_requested_at === null;
    }
}
