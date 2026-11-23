<?php

namespace App\Policies;

use Shagalni\Shared\Enums\UserRole;
use Shagalni\Shared\Models\JobVacancy;
use Shagalni\Shared\Models\User;

class JobSearchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::JobSeeker;
    }

    public function view(User $user, JobVacancy $job): bool
    {
        return $user->role === UserRole::JobSeeker;
    }
}
