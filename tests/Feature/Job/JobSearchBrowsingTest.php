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

test('job title search supports partial matching and empty input', function () {
    $owner = browsingOwner();
    browsingJob($owner, ['title' => 'Senior Laravel Engineer']);
    browsingJob($owner, ['title' => 'Product Designer']);

    $jobSeeker = User::factory()->create(['role' => UserRole::JobSeeker]);

    $this->actingAs($jobSeeker)
        ->getJson(route('jobs.search', ['title' => 'Laravel']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Senior Laravel Engineer');

    $this->actingAs($jobSeeker)
        ->getJson(route('jobs.search', ['title' => 'Missing']))
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAs($jobSeeker)
        ->getJson(route('jobs.search', ['title' => '']))
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('valid category filter returns matching jobs and excludes others', function () {
    $owner = browsingOwner();
    $software = JobCategory::firstOrCreate(['name' => 'Software']);
    $design = JobCategory::firstOrCreate(['name' => 'Design']);

    browsingJob($owner, [
        'title' => 'Software Job',
        'job_category_id' => $software->id,
    ]);
    browsingJob($owner, [
        'title' => 'Design Job',
        'job_category_id' => $design->id,
    ]);

    $jobSeeker = User::factory()->create(['role' => UserRole::JobSeeker]);

    $this->actingAs($jobSeeker)
        ->getJson(route('jobs.search', ['job_category_id' => $software->id]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Software Job');

    $this->actingAs($jobSeeker)
        ->getJson(route('jobs.search', ['job_category_id' => '']))
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('invalid category identifiers and non-existent category ids are rejected', function () {
    $jobSeeker = User::factory()->create(['role' => UserRole::JobSeeker]);

    $this->actingAs($jobSeeker)
        ->getJson(route('jobs.search', ['job_category_id' => 'invalid-category']))
        ->assertUnprocessable();

    $this->actingAs($jobSeeker)
        ->getJson(route('jobs.search', [
            'job_category_id' => '00000000-0000-4000-8000-000000000001',
        ]))
        ->assertUnprocessable();
});

test('location filter supports partial matching and empty input', function () {
    $owner = browsingOwner();
    browsingJob($owner, [
        'title' => 'Cairo Job',
        'location' => 'Cairo',
    ]);
    browsingJob($owner, [
        'title' => 'Alexandria Job',
        'location' => 'Alexandria',
    ]);

    $jobSeeker = User::factory()->create(['role' => UserRole::JobSeeker]);

    $this->actingAs($jobSeeker)
        ->getJson(route('jobs.search', ['location' => 'air']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.location', 'Cairo');

    $this->actingAs($jobSeeker)
        ->getJson(route('jobs.search', ['location' => 'Giza']))
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAs($jobSeeker)
        ->getJson(route('jobs.search', ['location' => '']))
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('combined title category and location filters use and logic', function () {
    $owner = browsingOwner();
    $software = JobCategory::firstOrCreate(['name' => 'Software']);
    $design = JobCategory::firstOrCreate(['name' => 'Design']);

    browsingJob($owner, [
        'title' => 'Senior Laravel Engineer',
        'location' => 'Cairo',
        'job_category_id' => $software->id,
    ]);
    browsingJob($owner, [
        'title' => 'Laravel Designer',
        'location' => 'Alexandria',
        'job_category_id' => $design->id,
    ]);
    browsingJob($owner, [
        'title' => 'Laravel Engineer',
        'location' => 'Cairo',
        'job_category_id' => $design->id,
    ]);

    $jobSeeker = User::factory()->create(['role' => UserRole::JobSeeker]);

    $this->actingAs($jobSeeker)
        ->getJson(route('jobs.search', [
            'title' => 'Laravel',
            'job_category_id' => $software->id,
        ]))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($jobSeeker)
        ->getJson(route('jobs.search', [
            'title' => 'Laravel',
            'location' => 'Cairo',
        ]))
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->actingAs($jobSeeker)
        ->getJson(route('jobs.search', [
            'job_category_id' => $software->id,
            'location' => 'Cairo',
        ]))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($jobSeeker)
        ->getJson(route('jobs.search', [
            'title' => 'Senior',
            'job_category_id' => $software->id,
            'location' => 'Cairo',
        ]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Senior Laravel Engineer');
});

test('filters cannot expose hidden or ineligible jobs', function () {
    $owner = browsingOwner();
    browsingJob($owner, [
        'title' => 'Visible Laravel Job',
        'location' => 'Cairo',
    ]);

    browsingJob($owner, [
        'title' => 'Closed Laravel Job',
        'location' => 'Cairo',
        'status' => JobStatus::Closed,
    ]);
    browsingJob($owner, [
        'title' => 'Expired Laravel Job',
        'location' => 'Cairo',
        'application_deadline' => now()->subDay()->toDateString(),
    ]);

    $this->actingAs(User::factory()->create(['role' => UserRole::JobSeeker]))
        ->getJson(route('jobs.search', [
            'title' => 'Laravel',
            'location' => 'Cairo',
        ]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Visible Laravel Job');
});

test('filtered pagination preserves search query parameters', function () {
    $owner = browsingOwner();
    $software = JobCategory::firstOrCreate(['name' => 'Software']);

    for ($index = 0; $index < 12; $index++) {
        browsingJob($owner, [
            'title' => 'Laravel Engineer '.$index,
            'location' => 'Cairo',
            'job_category_id' => $software->id,
        ]);
    }

    $jobSeeker = User::factory()->create(['role' => UserRole::JobSeeker]);
    $query = [
        'title' => 'Laravel',
        'job_category_id' => $software->id,
        'location' => 'Cairo',
    ];

    $this->actingAs($jobSeeker)
        ->getJson(route('jobs.search', $query))
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('per_page', 10)
        ->assertJsonPath('total', 12);

    $firstPage = $this->actingAs($jobSeeker)
        ->getJson(route('jobs.search', $query))
        ->assertOk()
        ->json();

    expect($firstPage['next_page_url'])
        ->toContain('title=Laravel')
        ->toContain('job_category_id='.$software->id)
        ->toContain('location=Cairo');

    $this->actingAs($jobSeeker)
        ->getJson(route('jobs.search', [...$query, 'page' => 2]))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('current_page', 2);
});

test('guest is redirected from job details', function () {
    $job = browsingJob(browsingOwner());

    $this->get(route('jobs.show', $job))
        ->assertRedirect(route('login'));
});

test('authenticated job seeker can view eligible job details', function () {
    $owner = browsingOwner();
    $job = browsingJob($owner, [
        'title' => 'Senior Laravel Engineer',
        'description' => 'Build reliable Laravel applications.',
        'requirements' => 'PHP and Laravel.',
        'required_skills' => 'Laravel, PHP',
        'salary_min' => 15000,
        'salary_max' => 25000,
        'experience_level' => 'senior',
        'application_deadline' => now()->toDateString(),
    ]);

    $owner->company->forceFill([
        'description' => 'Approved company description.',
        'website' => 'https://browsing.example',
    ])->save();

    $jobSeeker = User::factory()->create([
        'role' => UserRole::JobSeeker,
        'email_verified_at' => null,
    ]);

    $this->actingAs($jobSeeker)
        ->get(route('jobs.show', $job))
        ->assertOk()
        ->assertSee('Senior Laravel Engineer')
        ->assertSee('Build reliable Laravel applications.')
        ->assertSee('PHP and Laravel.')
        ->assertSee('Laravel, PHP')
        ->assertSee('15000')
        ->assertSee('25000')
        ->assertSee('Senior')
        ->assertSee('Approved company description.')
        ->assertSee('https://browsing.example')
        ->assertSee('Browsing Company');
});

test('company owner and admin cannot access job details', function () {
    $job = browsingJob(browsingOwner());

    $owner = $job->company->owner;
    $this->actingAs($owner)
        ->get(route('jobs.show', $job))
        ->assertForbidden();

    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($admin)
        ->get(route('jobs.show', $job))
        ->assertForbidden();
});

test('closed archived expired and inactive company jobs are not accessible by direct url', function (array $attributes) {
    $owner = browsingOwner(
        $attributes['company_status'] ?? RegistrationStatus::Approved,
        $attributes['owner_status'] ?? AccountStatus::Active,
    );

    $job = browsingJob($owner, $attributes['job'] ?? []);

    $this->actingAs(User::factory()->create(['role' => UserRole::JobSeeker]))
        ->get(route('jobs.show', $job))
        ->assertNotFound();
})->with([
    'closed' => [[
        'job' => ['status' => JobStatus::Closed],
    ]],
    'archived' => [[
        'job' => [
            'status' => JobStatus::Archived,
            'archived_at' => now(),
        ],
    ]],
    'expired' => [[
        'job' => ['application_deadline' => now()->subDay()->toDateString()],
    ]],
    'pending company' => [[
        'company_status' => RegistrationStatus::PendingApproval,
    ]],
    'rejected company' => [[
        'company_status' => RegistrationStatus::Rejected,
    ]],
    'suspended owner' => [[
        'owner_status' => AccountStatus::Suspended,
    ]],
    'deactivated owner' => [[
        'owner_status' => AccountStatus::Deactivated,
    ]],
]);

test('reactivating owner restores an eligible job detail', function () {
    $owner = browsingOwner(ownerStatus: AccountStatus::Suspended);
    $job = browsingJob($owner);

    $jobSeeker = User::factory()->create(['role' => UserRole::JobSeeker]);

    $this->actingAs($jobSeeker)
        ->get(route('jobs.show', $job))
        ->assertNotFound();

    $owner->forceFill(['status' => AccountStatus::Active])->save();

    $this->actingAs($jobSeeker)
        ->get(route('jobs.show', $job))
        ->assertOk();
});

test('nullable job and company fields render safely', function () {
    $owner = browsingOwner();
    $owner->company->forceFill([
        'description' => null,
        'website' => null,
        'logo' => null,
    ])->save();

    $job = browsingJob($owner, [
        'required_skills' => null,
        'salary_min' => null,
        'salary_max' => null,
        'experience_level' => null,
        'application_deadline' => null,
    ]);

    $this->actingAs(User::factory()->create(['role' => UserRole::JobSeeker]))
        ->get(route('jobs.show', $job))
        ->assertOk()
        ->assertSee('Browsing Company')
        ->assertDontSee('Required skills')
        ->assertDontSee('Salary range')
        ->assertDontSee('Experience level')
        ->assertDontSee('Application deadline');
});
