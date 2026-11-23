<?php

use App\Services\CompanyProfileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Shagalni\Shared\Enums\AccountStatus;
use Shagalni\Shared\Enums\RegistrationStatus;
use Shagalni\Shared\Enums\UserRole;
use Shagalni\Shared\Models\Company;
use Shagalni\Shared\Models\User;

function approvedCompanyOwnerForProfile(): array
{
    $owner = User::factory()->create([
        'role' => UserRole::CompanyOwner,
        'status' => AccountStatus::Active,
    ]);

    $company = Company::unguarded(fn () => Company::create([
        'owner_id' => $owner->id,
        'name' => 'Approved Company',
        'description' => 'Existing description',
        'industry' => 'Technology',
        'location' => 'Cairo',
        'website' => 'https://existing.example',
        'logo' => null,
        'registration_status' => RegistrationStatus::Approved,
    ]));

    return [$owner, $company];
}

test('approved company owner can view own company profile', function () {
    [$owner, $company] = approvedCompanyOwnerForProfile();

    $this->actingAs($owner)
        ->getJson(route('company-profile.show'))
        ->assertOk()
        ->assertJsonPath('company.id', $company->id)
        ->assertJsonPath('company.name', 'Approved Company')
        ->assertJsonPath('company.industry', 'Technology')
        ->assertJsonPath('company.registration_status', 'approved');
});

test('approved company owner can update direct profile fields', function () {
    [$owner] = approvedCompanyOwnerForProfile();

    $this->actingAs($owner)
        ->patchJson(route('company-profile.update'), [
            'description' => 'Updated description',
            'location' => 'Alexandria',
            'website' => 'https://updated.example',
        ])
        ->assertOk();

    $company = $owner->company()->firstOrFail();

    expect($company->description)->toBe('Updated description')
        ->and($company->location)->toBe('Alexandria')
        ->and($company->website)->toBe('https://updated.example')
        ->and($company->name)->toBe('Approved Company')
        ->and($company->industry)->toBe('Technology');
});

test('partial updates preserve omitted fields', function () {
    [$owner, $company] = approvedCompanyOwnerForProfile();

    $this->actingAs($owner)
        ->patchJson(route('company-profile.update'), [
            'location' => 'Giza',
        ])
        ->assertOk();

    $updated = $company->fresh();

    expect($updated->location)->toBe('Giza')
        ->and($updated->description)->toBe('Existing description')
        ->and($updated->website)->toBe('https://existing.example');
});

test('approved company owner can upload a company logo', function () {
    Storage::fake('public');
    [$owner] = approvedCompanyOwnerForProfile();

    $this->actingAs($owner)
        ->patch(route('company-profile.update'), [
            'logo' => UploadedFile::fake()->image('company-logo.png'),
        ])
        ->assertOk();

    $company = $owner->company()->firstOrFail();

    expect($company->logo)->not->toBeNull()
        ->and(str_starts_with($company->logo, 'company-logos/'))->toBeTrue();

    Storage::disk('public')->assertExists($company->logo);
});

test('approved company owner can replace an existing company logo', function () {
    Storage::fake('public');
    [$owner, $company] = approvedCompanyOwnerForProfile();

    Storage::disk('public')->put('company-logos/old-logo.png', 'old-logo');
    $company->update(['logo' => 'company-logos/old-logo.png']);

    $this->actingAs($owner)
        ->patch(route('company-profile.update'), [
            'logo' => UploadedFile::fake()->image('new-logo.webp'),
        ])
        ->assertOk();

    $newLogo = $company->fresh()->logo;

    expect($newLogo)->not->toBe('company-logos/old-logo.png');
    Storage::disk('public')->assertExists($newLogo);
    Storage::disk('public')->assertMissing('company-logos/old-logo.png');
});

test('database failure cleans the new logo and preserves the existing logo reference', function () {
    Storage::fake('public');
    [$owner, $company] = approvedCompanyOwnerForProfile();

    Storage::disk('public')->put('company-logos/old-logo.png', 'old-logo');
    $company->update(['logo' => 'company-logos/old-logo.png']);

    Company::updating(function (): never {
        throw new RuntimeException('Database update failed.');
    });

    expect(fn () => app(CompanyProfileService::class)
        ->update($company, [
            'logo' => UploadedFile::fake()->image('new-logo.png'),
        ]))->toThrow(RuntimeException::class, 'Database update failed.');

    $unchanged = $company->fresh();

    expect($unchanged->logo)->toBe('company-logos/old-logo.png');
    Storage::disk('public')->assertExists('company-logos/old-logo.png');
    expect(Storage::disk('public')->files('company-logos'))->toBe([
        'company-logos/old-logo.png',
    ]);
});

test('invalid logo formats are rejected', function () {
    Storage::fake('public');
    [$owner] = approvedCompanyOwnerForProfile();

    $this->actingAs($owner)
        ->patch(route('company-profile.update'), [
            'logo' => UploadedFile::fake()->create('company.pdf', 100, 'application/pdf'),
        ])
        ->assertSessionHasErrors(['logo']);

    expect($owner->company()->firstOrFail()->logo)->toBeNull();
});

test('logos larger than two megabytes are rejected', function () {
    Storage::fake('public');
    [$owner] = approvedCompanyOwnerForProfile();

    $this->actingAs($owner)
        ->patch(route('company-profile.update'), [
            'logo' => UploadedFile::fake()->image('too-large.jpg')->size(2049),
        ])
        ->assertSessionHasErrors(['logo']);

    expect($owner->company()->firstOrFail()->logo)->toBeNull();
});

test('guest cannot access company profile operations', function () {
    $this->getJson(route('company-profile.show'))
        ->assertUnauthorized();

    $this->patchJson(route('company-profile.update'), [
        'location' => 'Cairo',
    ])->assertUnauthorized();
});

test('job seeker cannot access company profile operations', function () {
    $jobSeeker = User::factory()->create([
        'role' => UserRole::JobSeeker,
    ]);

    $this->actingAs($jobSeeker)
        ->getJson(route('company-profile.show'))
        ->assertForbidden();

    $this->actingAs($jobSeeker)
        ->patchJson(route('company-profile.update'), [
            'location' => 'Cairo',
        ])
        ->assertForbidden();
});

test('company owner cannot access another company profile', function () {
    [$owner] = approvedCompanyOwnerForProfile();
    [$otherOwner, $otherCompany] = approvedCompanyOwnerForProfile();

    $this->actingAs($owner)
        ->getJson(route('company-profile.show'))
        ->assertOk();

    $this->actingAs($otherOwner)
        ->patchJson(route('company-profile.update'), [
            'location' => 'Different location',
            'owner_id' => $owner->id,
        ])
        ->assertUnprocessable();

    expect($otherCompany->fresh()->location)->toBe('Cairo')
        ->and($otherCompany->fresh()->owner_id)->toBe($otherOwner->id);
});

test('pending and rejected company owners cannot use approved profile workflow', function (RegistrationStatus $status) {
    $owner = User::factory()->create([
        'role' => UserRole::CompanyOwner,
        'status' => AccountStatus::Active,
    ]);

    Company::unguarded(fn () => Company::create([
        'owner_id' => $owner->id,
        'name' => 'Registration Company',
        'industry' => 'Technology',
        'location' => 'Cairo',
        'registration_status' => $status,
    ]));

    $this->actingAs($owner)
        ->getJson(route('company-profile.show'))
        ->assertForbidden();

    $this->actingAs($owner)
        ->patchJson(route('company-profile.update'), [
            'location' => 'Alexandria',
        ])
        ->assertForbidden();
})->with([
    'pending' => [RegistrationStatus::PendingApproval],
    'rejected' => [RegistrationStatus::Rejected],
]);

test('suspended and deactivated accounts cannot use company profile operations', function (AccountStatus $status) {
    [$owner] = approvedCompanyOwnerForProfile();
    $owner->forceFill(['status' => $status])->save();

    $this->actingAs($owner)
        ->getJson(route('company-profile.show'))
        ->assertRedirect(route('login'));

    $this->actingAs($owner)
        ->patchJson(route('company-profile.update'), [
            'location' => 'Alexandria',
        ])
        ->assertRedirect(route('login'));
})->with([
    'suspended' => [AccountStatus::Suspended],
    'deactivated' => [AccountStatus::Deactivated],
]);

test('pending deletion account cannot use company profile operations', function () {
    [$owner] = approvedCompanyOwnerForProfile();
    $owner->forceFill(['deletion_requested_at' => now()])->save();

    $this->actingAs($owner)
        ->getJson(route('company-profile.show'))
        ->assertRedirect(route('login'));

    $this->actingAs($owner)
        ->patchJson(route('company-profile.update'), [
            'location' => 'Alexandria',
        ])
        ->assertRedirect(route('login'));
});

test('missing company relationship is handled safely', function () {
    $owner = User::factory()->create([
        'role' => UserRole::CompanyOwner,
        'status' => AccountStatus::Active,
    ]);

    $this->actingAs($owner)
        ->getJson(route('company-profile.show'))
        ->assertNotFound();

    $this->actingAs($owner)
        ->patchJson(route('company-profile.update'), [
            'location' => 'Cairo',
        ])
        ->assertNotFound();

    expect(Company::count())->toBe(0);
});

test('protected fields cannot be modified through profile requests', function () {
    [$owner, $company] = approvedCompanyOwnerForProfile();

    $payload = [
        'location' => 'Changed Location',
        'name' => 'Changed Name',
        'industry' => 'Finance',
        'owner_id' => User::factory()->create()->id,
        'registration_status' => 'rejected',
        'reviewed_by' => User::factory()->create()->id,
        'reviewed_at' => now()->toIso8601String(),
        'rejection_reason' => 'Forged',
    ];

    $this->actingAs($owner)
        ->patchJson(route('company-profile.update'), $payload)
        ->assertUnprocessable();

    $unchanged = $company->fresh();

    expect($unchanged->location)->toBe('Cairo')
        ->and($unchanged->name)->toBe('Approved Company')
        ->and($unchanged->industry)->toBe('Technology')
        ->and($unchanged->owner_id)->toBe($owner->id)
        ->and($unchanged->registration_status)->toBe(RegistrationStatus::Approved)
        ->and($unchanged->rejection_reason)->toBeNull();
});

test('profile update does not create another company or change request', function () {
    [$owner, $company] = approvedCompanyOwnerForProfile();
    $companyCount = Company::count();

    $this->actingAs($owner)
        ->patchJson(route('company-profile.update'), [
            'description' => 'No duplicate',
        ])
        ->assertOk();

    expect(Company::count())->toBe($companyCount)
        ->and(Company::where('owner_id', $owner->id)->count())->toBe(1)
        ->and($company->fresh()->description)->toBe('No duplicate');
});
