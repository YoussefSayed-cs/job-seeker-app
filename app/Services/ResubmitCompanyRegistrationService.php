<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Shagalni\Shared\Enums\AccountStatus;
use Shagalni\Shared\Enums\RegistrationStatus;
use Shagalni\Shared\Enums\UserRole;
use Shagalni\Shared\Models\Company;
use Shagalni\Shared\Models\User;

class ResubmitCompanyRegistrationService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function resubmit(User $owner, array $data): Company
    {
        return DB::transaction(function () use ($owner, $data): Company {
            $lockedOwner = User::query()
                ->lockForUpdate()
                ->findOrFail($owner->id);

            $company = Company::query()
                ->where('owner_id', $lockedOwner->id)
                ->lockForUpdate()
                ->first();

            if (
                $company === null
                || $lockedOwner->role !== UserRole::CompanyOwner
                || $lockedOwner->status !== AccountStatus::Active
                || $lockedOwner->deletion_requested_at !== null
                || $lockedOwner->anonymized_at !== null
                || $lockedOwner->email_verified_at === null
                || $company->registration_status !== RegistrationStatus::Rejected
            ) {
                throw ValidationException::withMessages([
                    'registration' => 'This Company registration cannot be resubmitted.',
                ]);
            }

            $company->forceFill([
                'name' => $data['company_name'],
                'industry' => $data['industry'],
                'location' => $data['location'],
                'description' => $data['description'] ?? null,
                'website' => $data['website'] ?? null,
                'logo' => $data['logo'] ?? null,
                'registration_status' => RegistrationStatus::PendingApproval,
            ])->save();

            return $company;
        });
    }
}
