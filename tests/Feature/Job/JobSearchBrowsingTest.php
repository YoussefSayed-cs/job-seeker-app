<?php

use Illuminate\Support\Str;
use Shagalni\Shared\Enums\AccountStatus;
use Shagalni\Shared\Enums\EmploymentType;
use Shagalni\Shared\Enums\JobStatus;
use Shagalni\Shared\Enums\RegistrationStatus;
use Shagalni\Shared\Enums\UserRole;
use Shagalni\Shared\Enums\WorkLocationType;
use Shagalni\Shared\Models\Company;
use Shagalni\Shared\Models\JobCategory;
use Shagalni\Shared\Models\JobVacancy;
use Shagalni\Shared\Models\User;

function browsingOwner(
    RegistrationStatus $companyStatus = RegistrationStatus::Approved,
    AccountStatus $ownerStatus = AccountStatus::Active,
): User {
    $owner = User::factory()->create([
        'role' => UserRole::CompanyOwner,
        'status' => $ownerStatus,
    ]);

    Company::unguarded(fn () => Company::create([
        'owner_id' => $owner->id,
        'name' => 'Browsing Company',
        'industry' => 'Technology',
        'location' => 'Cairo',
        'logo' => null,
        'registration_status' => $companyStatus,
    ]));

    return $owner;
}

function browsingJob(User $owner, array $attributes = []): JobVacancy
{
    $category = JobCategory::firstOrCreate([
        'name' => 'Software '.Str::random(6),
    ]);

    $attributes = array_merge([
        'job_category_id' => $category->id,
        'title' => 'Laravel Developer',
        'description' => 'Build Laravel applications.',
        'requirements' => 'PHP and Laravel experience.',
        'location' => 'Cairo',
        'employment_type' => EmploymentType::FullTime,
        'work_location_type' => WorkLocationType::Hybrid,
        'status' => JobStatus::Active,
        'application_deadline' => null,
    ], $attributes);

    $job = $owner->company->jobVacancies()->make(array_diff_key(
        $attributes,
        array_flip(['status', 'archived_at']),
    ));

    $job->forceFill(array_intersect_key(
        $attributes,
        array_flip(['status', 'archived_at']),
    ))->save();

    return $job;
}

test('guest cannot browse jobs', function () {
    $this->getJson(route('jobs.search'))
        ->assertUnauthorized();
});

test('authenticated job seeker can browse eligible jobs', function () {
    $jobSeeker = User::factory()->create([
        'role' => UserRole::JobSeeker,
    ]);

    browsingJob(browsingOwner());

    $this->actingAs($jobSeeker)
        ->getJson(route('jobs.search'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Laravel Developer')
        ->assertJsonPath('data.0.company.name', 'Browsing Company');
});

test('company owner cannot browse through job seeker listing', function () {
    $owner = browsingOwner();

    $this->actingAs($owner)
        ->getJson(route('jobs.search'))
        ->assertForbidden();
});

test('admin cannot browse through job seeker listing', function () {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $this->actingAs($admin)
        ->getJson(route('jobs.search'))
        ->assertForbidden();
});

test('active job under approved company with active owner is visible', function () {
    browsingJob(browsingOwner());

    $this->actingAs(User::factory()->create(['role' => UserRole::JobSeeker]))
        ->getJson(route('jobs.search'))
        ->assertJsonCount(1, 'data');
});

test('jobs under pending or rejected companies are hidden', function (RegistrationStatus $status) {
    browsingJob(browsingOwner($status));

    $this->actingAs(User::factory()->create(['role' => UserRole::JobSeeker]))
        ->getJson(route('jobs.search'))
        ->assertJsonCount(0, 'data');
})->with([
    'pending' => [RegistrationStatus::PendingApproval],
    'rejected' => [RegistrationStatus::Rejected],
]);

test('jobs under suspended or deactivated owners are hidden', function (AccountStatus $status) {
    browsingJob(browsingOwner(ownerStatus: $status));

    $this->actingAs(User::factory()->create(['role' => UserRole::JobSeeker]))
        ->getJson(route('jobs.search'))
        ->assertJsonCount(0, 'data');
})->with([
    'suspended' => [AccountStatus::Suspended],
    'deactivated' => [AccountStatus::Deactivated],
]);

test('closed and archived jobs are hidden', function (JobStatus $status) {
    $owner = browsingOwner();
    browsingJob($owner, [
        'status' => $status,
        'archived_at' => $status === JobStatus::Archived ? now() : null,
    ]);

    $this->actingAs(User::factory()->create(['role' => UserRole::JobSeeker]))
        ->getJson(route('jobs.search'))
        ->assertJsonCount(0, 'data');
})->with([
    'closed' => [JobStatus::Closed],
    'archived' => [JobStatus::Archived],
]);

test('expired job is hidden and deadline day job is visible', function () {
    $owner = browsingOwner();
    browsingJob($owner, [
        'title' => 'Expired Job',
        'application_deadline' => now()->subDay()->toDateString(),
    ]);
    browsingJob($owner, [
        'title' => 'Deadline Day Job',
        'application_deadline' => now()->toDateString(),
    ]);

    $this->actingAs(User::factory()->create(['role' => UserRole::JobSeeker]))
        ->getJson(route('jobs.search'))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Deadline Day Job');
});

test('job without deadline remains eligible', function () {
    browsingJob(browsingOwner(), [
        'application_deadline' => null,
    ]);

    $this->actingAs(User::factory()->create(['role' => UserRole::JobSeeker]))
        ->getJson(route('jobs.search'))
        ->assertJsonCount(1, 'data');
});

test('pending deletion timestamp does not independently hide a job', function () {
    $owner = browsingOwner();
    browsingJob($owner);
    $owner->forceFill(['deletion_requested_at' => now()])->save();

    $this->actingAs(User::factory()->create(['role' => UserRole::JobSeeker]))
        ->getJson(route('jobs.search'))
        ->assertJsonCount(1, 'data');
});

test('company approval status changes affect listing visibility', function () {
    $owner = browsingOwner();
    browsingJob($owner);

    $jobSeeker = User::factory()->create(['role' => UserRole::JobSeeker]);

    $owner->company->forceFill([
        'registration_status' => RegistrationStatus::Rejected,
    ])->save();

    $this->actingAs($jobSeeker)
        ->getJson(route('jobs.search'))
        ->assertJsonCount(0, 'data');

    $owner->company->forceFill([
        'registration_status' => RegistrationStatus::Approved,
    ])->save();

    $this->actingAs($jobSeeker)
        ->getJson(route('jobs.search'))
        ->assertJsonCount(1, 'data');
});

test('owner suspension and reactivation affect listing visibility', function () {
    $owner = browsingOwner();
    browsingJob($owner);

    $jobSeeker = User::factory()->create(['role' => UserRole::JobSeeker]);

    $owner->forceFill(['status' => AccountStatus::Suspended])->save();

    $this->actingAs($jobSeeker)
        ->getJson(route('jobs.search'))
        ->assertJsonCount(0, 'data');

    $owner->forceFill(['status' => AccountStatus::Active])->save();

    $this->actingAs($jobSeeker)
        ->getJson(route('jobs.search'))
        ->assertJsonCount(1, 'data');
});

test('listing is paginated and only eligible jobs consume pages', function () {
    $owner = browsingOwner();

    for ($index = 0; $index < 12; $index++) {
        browsingJob($owner, [
            'title' => 'Eligible Job '.$index,
            'application_deadline' => null,
        ]);
    }

    browsingJob(browsingOwner(RegistrationStatus::Rejected), [
        'title' => 'Ineligible Job',
    ]);

    $jobSeeker = User::factory()->create(['role' => UserRole::JobSeeker]);

    $this->actingAs($jobSeeker)
        ->getJson(route('jobs.search'))
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('per_page', 10)
        ->assertJsonPath('total', 12)
        ->assertJsonPath('last_page', 2);

    $this->actingAs($jobSeeker)
        ->getJson(route('jobs.search', ['page' => 2]))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('current_page', 2);
});

test('listing is ordered newest first with stable secondary ordering', function () {
    $owner = browsingOwner();

    $oldJob = browsingJob($owner, ['title' => 'Older Job']);
    $oldJob->forceFill(['created_at' => now()->subDay()])->save();

    $newJob = browsingJob($owner, ['title' => 'Newer Job']);
    $newJob->forceFill(['created_at' => now()])->save();

    $this->actingAs(User::factory()->create(['role' => UserRole::JobSeeker]))
        ->getJson(route('jobs.search'))
        ->assertJsonPath('data.0.title', 'Newer Job');
});
