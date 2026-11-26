<?php

namespace App\Policies;

use Shagalni\Shared\Enums\AccountStatus;
use Shagalni\Shared\Enums\UserRole;
use Shagalni\Shared\Models\Application;
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

    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::JobSeeker;
    }

    public function view(User $user, Application $application): bool
    {
        return $user->role === UserRole::JobSeeker
            && $application->getAttribute('user_id') === $user->id;
    }

    public function withdraw(User $user, Application $application): bool
    {
        return $this->view($user, $application);
    }
}
