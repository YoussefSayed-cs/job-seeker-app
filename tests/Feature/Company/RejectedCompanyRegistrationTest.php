<?php

use Shagalni\Shared\Enums\AccountStatus;
use Shagalni\Shared\Enums\RegistrationStatus;
use Shagalni\Shared\Enums\UserRole;
use Shagalni\Shared\Models\Company;
use Shagalni\Shared\Models\User;

function createCompanyOwnerWithRegistration(
    RegistrationStatus $status,
    bool $verified = true,
): array {
    $owner = User::factory()->create([
        'role' => UserRole::CompanyOwner,
        'status' => AccountStatus::Active,
        'email_verified_at' => $verified ? now() : null,
    ]);

    $company = Company::unguarded(fn () => Company::create([
        'owner_id' => $owner->id,
        'name' => 'Original Company',
        'description' => 'Original description',
        'industry' => 'Technology',
        'location' => 'Cairo',
        'website' => 'https://original.example',
        'logo' => null,
        'registration_status' => $status,
        'rejection_reason' => $status === RegistrationStatus::Rejected
            ? 'Incorrect company information.'
            : null,
    ]));

    return [$owner, $company];
}

function validRejectedCompanyCorrectionPayload(): array
{
    return [
        'company_name' => 'Corrected Company',
        'industry' => 'Education',
        'location' => 'Alexandria',
        'description' => 'Corrected description',
        'website' => 'https://corrected.example',
        'logo' => null,
    ];
}

test('rejected company owner can view the existing rejection reason', function () {
    [$owner, $company] = createCompanyOwnerWithRegistration(RegistrationStatus::Rejected);

    $this->actingAs($owner)
        ->getJson(route('company-registration.rejected.show'))
        ->assertOk()
        ->assertJsonPath('company.id', $company->id)
        ->assertJsonPath('company.rejection_reason', 'Incorrect company information.')
        ->assertJsonPath('company.registration_status', 'rejected');
});

test('rejected company owner can resubmit the existing company record', function () {
    [$owner, $company] = createCompanyOwnerWithRegistration(RegistrationStatus::Rejected);
    $ownerCount = User::count();
    $companyCount = Company::count();

    $this->actingAs($owner)
        ->patchJson(
            route('company-registration.rejected.update'),
            validRejectedCompanyCorrectionPayload(),
        )
        ->assertOk()
        ->assertJsonPath('company.id', $company->id)
        ->assertJsonPath('company.registration_status', 'pending_approval');

    expect(User::count())->toBe($ownerCount)
        ->and(Company::count())->toBe($companyCount);

    $updated = $company->fresh();

    expect($updated->owner_id)->toBe($owner->id)
        ->and($updated->name)->toBe('Corrected Company')
        ->and($updated->industry)->toBe('Education')
        ->and($updated->location)->toBe('Alexandria')
        ->and($updated->description)->toBe('Corrected description')
        ->and($updated->website)->toBe('https://corrected.example')
        ->and($updated->registration_status)->toBe(RegistrationStatus::PendingApproval);
});

test('pending company owner cannot use rejected registration workflow', function () {
    [$owner] = createCompanyOwnerWithRegistration(RegistrationStatus::PendingApproval);

    $this->actingAs($owner)
        ->getJson(route('company-registration.rejected.show'))
        ->assertForbidden();

    $this->actingAs($owner)
        ->patchJson(
            route('company-registration.rejected.update'),
            validRejectedCompanyCorrectionPayload(),
        )
        ->assertForbidden();
});

test('approved company owner cannot use rejected registration workflow', function () {
    [$owner] = createCompanyOwnerWithRegistration(RegistrationStatus::Approved);

    $this->actingAs($owner)
        ->getJson(route('company-registration.rejected.show'))
        ->assertForbidden();

    $this->actingAs($owner)
        ->patchJson(
            route('company-registration.rejected.update'),
            validRejectedCompanyCorrectionPayload(),
        )
        ->assertForbidden();
});

test('job seeker cannot use rejected registration workflow', function () {
    $jobSeeker = User::factory()->create([
        'role' => UserRole::JobSeeker,
    ]);

    $this->actingAs($jobSeeker)
        ->getJson(route('company-registration.rejected.show'))
        ->assertForbidden();
});

test('company owner cannot view or resubmit another company registration', function () {
    [$owner, $ownerCompany] = createCompanyOwnerWithRegistration(RegistrationStatus::Rejected);
    [$otherOwner, $otherCompany] = createCompanyOwnerWithRegistration(RegistrationStatus::Rejected);

    $this->actingAs($owner)
        ->getJson(route('company-registration.rejected.show'))
        ->assertOk();

    $payload = validRejectedCompanyCorrectionPayload();
    $payload['company_name'] = 'Unauthorized Edit';

    $this->actingAs($otherOwner)
        ->patchJson(route('company-registration.rejected.update'), $payload)
        ->assertOk();

    expect($ownerCompany->fresh()->name)->toBe('Original Company')
        ->and($otherCompany->fresh()->name)->toBe('Unauthorized Edit')
        ->and($ownerCompany->fresh()->owner_id)->toBe($owner->id)
        ->and($otherCompany->fresh()->owner_id)->toBe($otherOwner->id);
});

test('unverified company owner cannot resubmit rejected registration', function () {
    [$owner] = createCompanyOwnerWithRegistration(
        RegistrationStatus::Rejected,
        false,
    );

    $this->actingAs($owner)
        ->getJson(route('company-registration.rejected.show'))
        ->assertForbidden();

    $this->actingAs($owner)
        ->patchJson(
            route('company-registration.rejected.update'),
            validRejectedCompanyCorrectionPayload(),
        )
        ->assertForbidden();
});

test('guest cannot access rejected registration workflow', function () {
    $this->getJson(route('company-registration.rejected.show'))
        ->assertUnauthorized();

    $this->patchJson(
        route('company-registration.rejected.update'),
        validRejectedCompanyCorrectionPayload(),
    )->assertUnauthorized();
});

test('protected fields cannot be changed through a crafted request', function () {
    [$owner, $company] = createCompanyOwnerWithRegistration(RegistrationStatus::Rejected);
    $payload = [
        ...validRejectedCompanyCorrectionPayload(),
        'owner_id' => User::factory()->create()->id,
        'registration_status' => 'approved',
        'reviewed_by' => User::factory()->create()->id,
        'reviewed_at' => now()->toIso8601String(),
        'rejection_reason' => 'Forged',
    ];

    $this->actingAs($owner)
        ->patchJson(route('company-registration.rejected.update'), $payload)
        ->assertUnprocessable();

    $unchanged = $company->fresh();

    expect($unchanged->owner_id)->toBe($owner->id)
        ->and($unchanged->registration_status)->toBe(RegistrationStatus::Rejected)
        ->and($unchanged->rejection_reason)->toBe('Incorrect company information.');
});

test('resubmission validates required erd fields', function () {
    [$owner] = createCompanyOwnerWithRegistration(RegistrationStatus::Rejected);

    $this->actingAs($owner)
        ->patchJson(route('company-registration.rejected.update'), [
            'description' => null,
            'website' => null,
            'logo' => null,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['company_name', 'industry', 'location']);
});

test('repeated resubmission after first success cannot return to rejected workflow', function () {
    [$owner, $company] = createCompanyOwnerWithRegistration(RegistrationStatus::Rejected);

    $this->actingAs($owner)
        ->patchJson(
            route('company-registration.rejected.update'),
            validRejectedCompanyCorrectionPayload(),
        )
        ->assertOk();

    $this->actingAs($owner)
        ->patchJson(
            route('company-registration.rejected.update'),
            validRejectedCompanyCorrectionPayload(),
        )
        ->assertForbidden();

    expect($company->fresh()->registration_status)
        ->toBe(RegistrationStatus::PendingApproval);
});
