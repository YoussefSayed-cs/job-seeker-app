<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Shagalni\Shared\Enums\AccountStatus;
use Shagalni\Shared\Enums\EmploymentType;
use Shagalni\Shared\Enums\JobStatus;
use Shagalni\Shared\Enums\RegistrationStatus;
use Shagalni\Shared\Enums\UserRole;
use Shagalni\Shared\Enums\WorkLocationType;
use Shagalni\Shared\Events\ApplicationSubmitted;
use Shagalni\Shared\Jobs\ProcessResumeAnalysis;
use Shagalni\Shared\Models\Application;
use Shagalni\Shared\Models\Company;
use Shagalni\Shared\Models\JobCategory;
use Shagalni\Shared\Models\JobVacancy;
use Shagalni\Shared\Models\SavedJob;
use Shagalni\Shared\Models\User;

function savedJobOwner(
    RegistrationStatus $companyStatus = RegistrationStatus::Approved,
    AccountStatus $ownerStatus = AccountStatus::Active,
): User {
    $owner = User::factory()->create([
        'role' => UserRole::CompanyOwner,
        'status' => $ownerStatus,
    ]);

    Company::unguarded(fn () => Company::create([
        'owner_id' => $owner->id,
        'name' => 'Saved Jobs Company',
        'industry' => 'Technology',
        'location' => 'Cairo',
        'logo' => null,
        'registration_status' => $companyStatus,
    ]));

    return $owner;
}

function savedJobJob(User $owner, array $attributes = []): JobVacancy
{
    $category = JobCategory::firstOrCreate([
        'name' => 'Saved Jobs '.Str::random(6),
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

function savedJobFor(User $user, JobVacancy $job): SavedJob
{
    return SavedJob::unguarded(fn () => SavedJob::create([
        'user_id' => $user->id,
        'job_vacancy_id' => $job->id,
    ]));
}

test('a job seeker can save an eligible job', function () {
    $jobSeeker = User::factory()->create(['role' => UserRole::JobSeeker]);
    $job = savedJobJob(savedJobOwner());

    $this->actingAs($jobSeeker)
        ->postJson(route('saved-jobs.store'), ['job_id' => $job->id])
        ->assertCreated()
        ->assertJsonPath('saved_job.job.id', $job->id)
        ->assertJsonPath('saved_job.available', true);

    expect(SavedJob::where('user_id', $jobSeeker->id)->where('job_vacancy_id', $job->id)->exists())->toBeTrue();
});

test('duplicate saves are handled gracefully', function () {
    $jobSeeker = User::factory()->create(['role' => UserRole::JobSeeker]);
    $job = savedJobJob(savedJobOwner());
    savedJobFor($jobSeeker, $job);

    $this->actingAs($jobSeeker)
        ->postJson(route('saved-jobs.store'), ['job_id' => $job->id])
        ->assertOk()
        ->assertJsonPath('message', 'Job is already saved.');

    expect(SavedJob::where('user_id', $jobSeeker->id)->where('job_vacancy_id', $job->id)->count())->toBe(1);
});

test('invalid and missing job uuids are rejected', function () {
    $jobSeeker = User::factory()->create(['role' => UserRole::JobSeeker]);

    $this->actingAs($jobSeeker)
        ->postJson(route('saved-jobs.store'), ['job_id' => 'not-a-uuid'])
        ->assertUnprocessable();

    $this->actingAs($jobSeeker)
        ->postJson(route('saved-jobs.store'), ['job_id' => Str::uuid()->toString()])
        ->assertUnprocessable();
});

test('non job seekers cannot save jobs', function (UserRole $role) {
    $job = savedJobJob(savedJobOwner());
    $user = User::factory()->create(['role' => $role]);

    $this->actingAs($user)
        ->postJson(route('saved-jobs.store'), ['job_id' => $job->id])
        ->assertForbidden();
})->with([
    'company owner' => [UserRole::CompanyOwner],
    'admin' => [UserRole::Admin],
]);

test('unavailable jobs cannot be newly bookmarked', function (array $attributes) {
    $jobSeeker = User::factory()->create(['role' => UserRole::JobSeeker]);
    $job = savedJobJob(savedJobOwner(), $attributes);

    $this->actingAs($jobSeeker)
        ->postJson(route('saved-jobs.store'), ['job_id' => $job->id])
        ->assertUnprocessable();

    expect(SavedJob::where('user_id', $jobSeeker->id)->exists())->toBeFalse();
})->with([
    'closed' => [['status' => JobStatus::Closed]],
    'archived' => [['status' => JobStatus::Archived, 'archived_at' => now()]],
    'expired' => [['application_deadline' => now()->subDay()->toDateString()]],
]);

test('a user sees only their own saved jobs and the list is paginated', function () {
    $owner = savedJobOwner();
    $jobSeeker = User::factory()->create(['role' => UserRole::JobSeeker]);
    $other = User::factory()->create(['role' => UserRole::JobSeeker]);

    for ($index = 0; $index < 12; $index++) {
        savedJobFor($jobSeeker, savedJobJob($owner, ['title' => 'Saved '.$index]));
    }

    savedJobFor($other, savedJobJob($owner, ['title' => 'Other User Job']));

    $this->actingAs($jobSeeker)
        ->getJson(route('saved-jobs.index'))
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('per_page', 10)
        ->assertJsonPath('total', 12);

    $this->actingAs($jobSeeker)
        ->getJson(route('saved-jobs.index', ['page' => 2]))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('current_page', 2);
});

test('unavailable saved jobs remain visible with the correct reason', function () {
    $jobSeeker = User::factory()->create(['role' => UserRole::JobSeeker]);
    $closed = savedJobJob(savedJobOwner(), ['status' => JobStatus::Closed, 'title' => 'Closed Job']);
    $expired = savedJobJob(savedJobOwner(), ['application_deadline' => now()->subDay()->toDateString(), 'title' => 'Expired Job']);
    $inactiveCompany = savedJobJob(savedJobOwner(RegistrationStatus::Rejected), ['title' => 'Inactive Company Job']);

    savedJobFor($jobSeeker, $closed);
    savedJobFor($jobSeeker, $expired);
    savedJobFor($jobSeeker, $inactiveCompany);

    $this->actingAs($jobSeeker)
        ->getJson(route('saved-jobs.index'))
        ->assertOk()
        ->assertJsonFragment(['available' => false, 'unavailable_reason' => 'closed'])
        ->assertJsonFragment(['available' => false, 'unavailable_reason' => 'expired'])
        ->assertJsonFragment(['available' => false, 'unavailable_reason' => 'company_inactive']);
});

test('a user can remove their own bookmark without deleting the job', function () {
    $jobSeeker = User::factory()->create(['role' => UserRole::JobSeeker]);
    $job = savedJobJob(savedJobOwner());
    $savedJob = savedJobFor($jobSeeker, $job);

    $this->actingAs($jobSeeker)
        ->deleteJson(route('saved-jobs.destroy', $savedJob))
        ->assertOk();

    expect(SavedJob::whereKey($savedJob->id)->exists())->toBeFalse()
        ->and(JobVacancy::whereKey($job->id)->exists())->toBeTrue();
});

test('a user cannot view or remove another users bookmark', function () {
    $owner = User::factory()->create(['role' => UserRole::JobSeeker]);
    $other = User::factory()->create(['role' => UserRole::JobSeeker]);
    $savedJob = savedJobFor($owner, savedJobJob(savedJobOwner()));

    $this->actingAs($other)
        ->getJson(route('saved-jobs.show', $savedJob))
        ->assertForbidden();

    $this->actingAs($other)
        ->deleteJson(route('saved-jobs.destroy', $savedJob))
        ->assertForbidden();
});

test('saving a job does not create application state or dispatch application events', function () {
    Event::fake([ApplicationSubmitted::class]);
    Notification::fake();
    Queue::fake();

    $jobSeeker = User::factory()->create(['role' => UserRole::JobSeeker]);
    $job = savedJobJob(savedJobOwner());

    $this->actingAs($jobSeeker)
        ->postJson(route('saved-jobs.store'), ['job_id' => $job->id])
        ->assertCreated();

    expect(Application::where('user_id', $jobSeeker->id)->exists())->toBeFalse();
    Event::assertNotDispatched(ApplicationSubmitted::class);
    Notification::assertNothingSent();
    Queue::assertNotPushed(ProcessResumeAnalysis::class);
});

test('permanent job deletion cascades to the saved bookmark', function () {
    $jobSeeker = User::factory()->create(['role' => UserRole::JobSeeker]);
    $job = savedJobJob(savedJobOwner());
    $savedJob = savedJobFor($jobSeeker, $job);

    $job->delete();

    expect(SavedJob::whereKey($savedJob->id)->exists())->toBeFalse();
});

test('an unavailable saved job has read only details and cannot be applied to', function () {
    $jobSeeker = User::factory()->create(['role' => UserRole::JobSeeker]);
    $job = savedJobJob(savedJobOwner(), ['status' => JobStatus::Closed]);
    $savedJob = savedJobFor($jobSeeker, $job);

    $this->actingAs($jobSeeker)
        ->getJson(route('saved-jobs.show', $savedJob))
        ->assertOk()
        ->assertJsonPath('saved_job.available', false)
        ->assertJsonPath('saved_job.unavailable_reason', 'closed');

    expect($job->fresh()->isAcceptingApplications())->toBeFalse();
});
