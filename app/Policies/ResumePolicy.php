<?php

namespace App\Policies;

use Shagalni\Shared\Enums\AccountStatus;
use Shagalni\Shared\Enums\UserRole;
use Shagalni\Shared\Models\Resume;
use Shagalni\Shared\Models\User;

class ResumePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isActiveJobSeeker($user);
    }

    public function create(User $user): bool
    {
        return $this->isActiveJobSeeker($user);
    }

    public function view(User $user, Resume $resume): bool
    {
        return $this->isActiveJobSeeker($user) && $resume->getAttribute('user_id') === $user->id;
    }

    public function update(User $user, Resume $resume): bool
    {
        return $this->isActiveJobSeeker($user) && $resume->getAttribute('user_id') === $user->id;
    }

    private function isActiveJobSeeker(User $user): bool
    {
        return $user->role === UserRole::JobSeeker
            && $user->status === AccountStatus::Active;
    }
}
