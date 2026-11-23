<?php

namespace App\Policies;

use Shagalni\Shared\Enums\AccountStatus;
use Shagalni\Shared\Enums\RegistrationStatus;
use Shagalni\Shared\Enums\UserRole;
use Shagalni\Shared\Models\Company;
use Shagalni\Shared\Models\User;

class CompanyPolicy
{
    public function viewOwnProfile(User $user, Company $company): bool
    {
        return $this->canUseApprovedCompanyProfile($user, $company);
    }

    public function updateProfile(User $user, Company $company): bool
    {
        return $this->canUseApprovedCompanyProfile($user, $company);
    }

    public function viewRejectedRegistration(User $user, Company $company): bool
    {
        return $this->canUseRejectedRegistrationWorkflow($user, $company);
    }

    public function resubmitRegistration(User $user, Company $company): bool
    {
        return $this->canUseRejectedRegistrationWorkflow($user, $company);
    }

    private function canUseRejectedRegistrationWorkflow(User $user, Company $company): bool
    {
        return $user->role === UserRole::CompanyOwner
            && $user->status === AccountStatus::Active
            && $user->deletion_requested_at === null
            && $user->anonymized_at === null
            && $user->email_verified_at !== null
            && $company->owner_id === $user->id
            && $company->registration_status === RegistrationStatus::Rejected;
    }

    private function canUseApprovedCompanyProfile(User $user, Company $company): bool
    {
        return $user->role === UserRole::CompanyOwner
            && $user->status === AccountStatus::Active
            && $user->deletion_requested_at === null
            && $user->anonymized_at === null
            && $user->email_verified_at !== null
            && $company->owner_id === $user->id
            && $company->registration_status === RegistrationStatus::Approved;
    }
}
