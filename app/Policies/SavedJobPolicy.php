<?php

namespace App\Policies;

use Shagalni\Shared\Enums\UserRole;
use Shagalni\Shared\Models\SavedJob;
use Shagalni\Shared\Models\User;

class SavedJobPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::JobSeeker;
    }

    public function view(User $user, SavedJob $savedJob): bool
    {
        return $user->role === UserRole::JobSeeker && $savedJob->user_id === $user->id;    
    }

    public function delete(User $user, SavedJob $savedJob): bool
    {
        return $user->role === UserRole::JobSeeker && $savedJob->user_id === $user->id;     
    }
}
