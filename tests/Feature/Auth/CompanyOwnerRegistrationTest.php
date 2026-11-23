<?php

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Shagalni\Shared\Enums\AccountStatus;
use Shagalni\Shared\Enums\RegistrationStatus;
use Shagalni\Shared\Enums\UserRole;
use Shagalni\Shared\Models\Company;
use Shagalni\Shared\Models\User;

function validCompanyOwnerRegistrationPayload(): array
{
    return [
        'full_name' => 'Company Owner',
        'email' => 'Owner@Example.com',
        'phone' => '01012345678',
        'password' => 'password',
        'password_confirmation' => 'password',
        'company_name' => 'Example Company',
        'industry' => 'Technology',
        'location' => 'Cairo',
        'description' => 'A software company.',
        'website' => 'https://example.com',
    ];
}

test('company owner registration creates one active owner and pending company', function () {
    Notification::fake();

    $response = $this->post(route('company-owner.register.store'), validCompanyOwnerRegistrationPayload());

    $response->assertRedirect(route('verification.notice'))
        ->assertSessionHas('status', 'verification-link-sent');

    $this->assertAuthenticated();

    $owner = User::where('email', 'owner@example.com')->firstOrFail();

    expect($owner->full_name)->toBe('Company Owner')
        ->and($owner->phone)->toBe('01012345678')
        ->and($owner->role)->toBe(UserRole::CompanyOwner)
        ->and($owner->status)->toBe(AccountStatus::Active)
        ->and($owner->email_verified_at)->toBeNull()
        ->and(Hash::check('password', $owner->password))->toBeTrue();

    $company = Company::where('owner_id', $owner->id)->firstOrFail();

    expect(Company::where('owner_id', $owner->id)->count())->toBe(1)
        ->and($company->name)->toBe('Example Company')
        ->and($company->industry)->toBe('Technology')
        ->and($company->location)->toBe('Cairo')
        ->and($company->description)->toBe('A software company.')
        ->and($company->website)->toBe('https://example.com')
        ->and($company->registration_status)->toBe(RegistrationStatus::PendingApproval)
        ->and($company->rejection_reason)->toBeNull()
        ->and($company->reviewed_by)->toBeNull()
        ->and($company->reviewed_at)->toBeNull();

    Notification::assertSentTo($owner, VerifyEmail::class);
});

test('company owner registration rejects duplicate email', function () {
    User::factory()->create(['email' => 'owner@example.com']);

    $payload = validCompanyOwnerRegistrationPayload();

    $this->post(route('company-owner.register.store'), $payload)
        ->assertSessionHasErrors(['email']);

    expect(User::where('email', 'owner@example.com')->count())->toBe(1)
        ->and(Company::count())->toBe(0);
});

test('company owner registration requires personal and company fields', function () {
    $this->post(route('company-owner.register.store'), [])
        ->assertSessionHasErrors([
            'full_name',
            'email',
            'phone',
            'password',
            'company_name',
            'industry',
            'location',
        ]);

    expect(User::count())->toBe(0)
        ->and(Company::count())->toBe(0);
});

test('company owner registration rejects protected role and account fields', function (string $field) {
    $payload = validCompanyOwnerRegistrationPayload();
    $payload[$field] = match ($field) {
        'role' => 'admin',
        'status' => 'deactivated',
        'registration_status' => 'approved',
        'owner_id' => '00000000-0000-4000-8000-000000000001',
        default => 'unexpected',
    };

    $this->post(route('company-owner.register.store'), $payload)
        ->assertSessionHasNoErrors();

    $owner = User::where('email', 'owner@example.com')->firstOrFail();
    $company = $owner->company()->firstOrFail();

    expect($owner->role)->toBe(UserRole::CompanyOwner)
        ->and($owner->status)->toBe(AccountStatus::Active)
        ->and($company->registration_status)->toBe(RegistrationStatus::PendingApproval)
        ->and($company->owner_id)->toBe($owner->id);
})->with([
    'role',
    'status',
    'registration_status',
    'owner_id',
]);
