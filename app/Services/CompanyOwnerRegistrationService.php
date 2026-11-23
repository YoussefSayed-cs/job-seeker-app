<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Shagalni\Shared\Enums\AccountStatus;
use Shagalni\Shared\Enums\RegistrationStatus;
use Shagalni\Shared\Enums\UserRole;
use Shagalni\Shared\Models\Company;
use Shagalni\Shared\Models\User;

class CompanyOwnerRegistrationService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function register(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $owner = new User([
                'full_name' => $data['full_name'],
                'email' => Str::lower($data['email']),
                'phone' => $data['phone'],
                'password' => $data['password'],
            ]);

            $owner->forceFill([
                'role' => UserRole::CompanyOwner,
                'status' => AccountStatus::Active,
            ])->save();

            $company = new Company([
                'name' => $data['company_name'],
                'industry' => $data['industry'],
                'location' => $data['location'],
                'description' => $data['description'] ?? null,
                'website' => $data['website'] ?? null,
            ]);

            $company->forceFill([
                'owner_id' => $owner->id,
                'registration_status' => RegistrationStatus::PendingApproval,
            ])->save();

            return $owner;
        });
    }
}
