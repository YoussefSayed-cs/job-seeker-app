<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Shagalni\Shared\Enums\AccountStatus;
use Shagalni\Shared\Enums\RegistrationStatus;
use Shagalni\Shared\Enums\UserRole;
use Shagalni\Shared\Models\User;

class AccountLifecycleService
{
    public const REACTIVATION_LINK_HOURS = 24;

    public const DELETION_GRACE_DAYS = 30;

    public function canManageFromJobSeekerPortal(User $user): bool
    {
        if ($user->role === UserRole::JobSeeker) {
            return true;
        }

        return $user->role === UserRole::CompanyOwner
            && in_array($user->company?->registration_status, [
                RegistrationStatus::PendingApproval,
                RegistrationStatus::Rejected,
            ], true);
    }

    public function deactivateOwnAccount(User $user): User
    {
        return DB::transaction(function () use ($user) {
            $locked = User::query()->with('company')->lockForUpdate()->findOrFail($user->id);

            if (
                ! $this->canManageFromJobSeekerPortal($locked)
                || $locked->status !== AccountStatus::Active
                || $locked->deletion_requested_at !== null
                || $locked->anonymized_at !== null
            ) {
                throw ValidationException::withMessages([
                    'account' => 'This account cannot be deactivated from this portal.',
                ]);
            }

            $locked->forceFill(['status' => AccountStatus::Deactivated])->save();

            return $locked;
        });
    }

    public function requestDeletion(User $user): User
    {
        return DB::transaction(function () use ($user) {
            $locked = User::query()->with('company')->lockForUpdate()->findOrFail($user->id);

            if (
                ! $this->canManageFromJobSeekerPortal($locked)
                || $locked->status !== AccountStatus::Active
                || $locked->deletion_requested_at !== null
                || $locked->anonymized_at !== null
            ) {
                throw ValidationException::withMessages([
                    'account' => 'This account cannot request deletion from this portal.',
                ]);
            }

            $locked->forceFill(['deletion_requested_at' => now()])->save();

            return $locked;
        });
    }

    public function cancelDeletion(User $user, int $requestedAt): User
    {
        return DB::transaction(function () use ($user, $requestedAt) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            if (
                $locked->anonymized_at !== null
                || $locked->deletion_requested_at === null
                || $locked->deletion_requested_at->getTimestamp() !== $requestedAt
            ) {
                throw ValidationException::withMessages([
                    'account' => 'This cancellation link is invalid or has already been used.',
                ]);
            }

            $graceEndsAt = $locked->deletion_requested_at
                ->addDays(self::DELETION_GRACE_DAYS);

            if (! now()->lt($graceEndsAt)) {
                throw ValidationException::withMessages([
                    'account' => 'The account recovery period has expired.',
                ]);
            }

            $locked->forceFill(['deletion_requested_at' => null])->save();

            return $locked;
        });
    }

    public function canRequestReactivation(User $user): bool
    {
        return $this->canManageFromJobSeekerPortal($user)
            && $user->status === AccountStatus::Deactivated
            && $user->anonymized_at === null;
    }

    public function issueReactivationToken(User $user): string
    {
        return DB::transaction(function () use ($user) {
            $locked = User::query()->with('company')->lockForUpdate()->findOrFail($user->id);

            if (! $this->canRequestReactivation($locked)) {
                throw ValidationException::withMessages([
                    'account' => 'This account cannot request a reactivation link.',
                ]);
            }

            $token = Str::random(64);

            Cache::put(
                $this->tokenKey($locked),
                $token,
                now()->addHours(self::REACTIVATION_LINK_HOURS)
            );

            return $token;
        });
    }

    public function reactivateWithToken(User $user, string $token): User
    {
        return DB::transaction(function () use ($user, $token) {
            $locked = User::query()->with('company')->lockForUpdate()->findOrFail($user->id);
            $stored = Cache::get($this->tokenKey($locked));

            if (
                ! is_string($stored)
                || ! hash_equals($stored, $token)
                || ! $this->canRequestReactivation($locked)
            ) {
                throw ValidationException::withMessages([
                    'account' => 'This reactivation link is invalid or has already been used.',
                ]);
            }

            $locked->forceFill(['status' => AccountStatus::Active])->save();
            Cache::forget($this->tokenKey($locked));

            return $locked;
        });
    }

    private function tokenKey(User $user): string
    {
        return 'account-reactivation:'.$user->id;
    }
}
