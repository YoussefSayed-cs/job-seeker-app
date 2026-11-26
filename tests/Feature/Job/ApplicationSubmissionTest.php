<?php

use App\Services\ResumeStorageService;
use Illuminate\Support\Facades\Event;
use Shagalni\Shared\Enums\AccountStatus;
use Shagalni\Shared\Enums\ApplicationStatus;
use Shagalni\Shared\Enums\EmploymentType;
use Shagalni\Shared\Enums\JobStatus;
use Shagalni\Shared\Enums\RegistrationStatus;
use Shagalni\Shared\Enums\UserRole;
use Shagalni\Shared\Enums\WorkLocationType;
use Shagalni\Shared\Events\ApplicationSubmitted;
use Shagalni\Shared\Models\Application;
use Shagalni\Shared\Models\ApplicationStatusHistory;
use Shagalni\Shared\Models\Company;
use Shagalni\Shared\Models\JobCategory;
use Shagalni\Shared\Models\JobVacancy;
use Shagalni\Shared\Models\Resume;
use Shagalni\Shared\Models\ResumeSnapshot;
use Shagalni\Shared\Models\User;
use Tests\Fakes\FakeResumeStorageService;

function applicationStorage(): FakeResumeStorageService
{
    $storage = new FakeResumeStorageService;
    app()->instance(ResumeStorageService::class, $storage);

    return $storage;
}

function applicationOwner(
    AccountStatus $status = AccountStatus::Active,
    RegistrationStatus $registrationStatus = RegistrationStatus::Approved,
): User {
    $owner = User::factory()->create([
        'role' => UserRole::CompanyOwner,
        'status' => $status,
    ]);

    Company::unguarded(fn () => Company::create([
        'owner_id' => $owner->id,
        'name' => 'Application Test Company',
        'industry' => 'Technology',
        'location' => 'Cairo',
        'registration_status' => $registrationStatus,
    ]));

    return $owner;
}

function applicationJob(User $owner, array $attributes = []): JobVacancy
{
    $category = JobCategory::firstOrCreate([
        'name' => 'Application Test Category',
    ]);

    $attributes = array_merge([
        'job_category_id' => $category->id,
        'title' => 'Application Test Job',
        'description' => 'Build software.',
        'requirements' => 'PHP experience.',
        'location' => 'Cairo',
        'employment_type' => EmploymentType::FullTime,
        'work_location_type' => WorkLocationType::Hybrid,
        'status' => JobStatus::Active,
        'application_deadline' => null,
        'archived_at' => null,
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

function applicationResume(User $user): Resume
{
    return $user->resume()->create([
        'file_path' => 'resumes/'.$user->id.'/current.pdf',
        'original_name' => 'My resume.pdf',
        'file_size' => 1234,
        'file_type' => 'pdf',
    ]);
}

function existingApplication(User $user, JobVacancy $job, ApplicationStatus $status): Application
{
    return Application::query()->create([
        'job_vacancy_id' => $job->id,
        'company_id' => $job->company_id,
        'user_id' => $user->id,
        'status' => $status,
        'job_title' => $job->title,
        'company_name' => $job->company->name,
        'submitted_at' => now(),
    ]);
}

test('a job seeker submission creates the application snapshot and initial history', function () {
    $storage = applicationStorage();
    $jobSeeker = User::factory()->create(['role' => UserRole::JobSeeker]);
    $resume = applicationResume($jobSeeker);
    $job = applicationJob(applicationOwner());
    Event::fake([ApplicationSubmitted::class]);

    $this->actingAs($jobSeeker)
        ->post(route('jobs.apply', $job))
        ->assertRedirect(route('jobs.show', $job))
        ->assertSessionHas('status', 'Application submitted successfully.');

    $application = Application::query()->firstOrFail();
    $snapshot = ResumeSnapshot::query()->firstOrFail();
    $history = ApplicationStatusHistory::query()->firstOrFail();

    expect($application->status)->toBe(ApplicationStatus::Pending)
        ->and($application->job_vacancy_id)->toBe($job->id)
        ->and($application->company_id)->toBe($job->company_id)
        ->and($application->user_id)->toBe($jobSeeker->id)
        ->and($application->job_title)->toBe($job->title)
        ->and($application->company_name)->toBe($job->company->name)
        ->and($application->submitted_at)->not->toBeNull()
        ->and($snapshot->application_id)->toBe($application->id)
        ->and($snapshot->file_path)->toBe('resume-snapshots/'.$application->id.'.pdf')
        ->and($snapshot->original_name)->toBe($resume->original_name)
        ->and($history->from_status)->toBeNull()
        ->and($history->to_status)->toBe(ApplicationStatus::Pending)
        ->and($history->changed_by)->toBe($jobSeeker->id)
        ->and($storage->copies)->toBe([[
            'source' => $resume->file_path,
            'folder' => 'resume-snapshots',
            'name' => $application->id,
        ]]);

    Event::assertDispatched(ApplicationSubmitted::class, fn (ApplicationSubmitted $event): bool => $event->application->is($application));
});

test('submission requires a resume and a verified email', function () {
    $owner = applicationOwner();
    $job = applicationJob($owner);
    $jobSeekerWithoutResume = User::factory()->create(['role' => UserRole::JobSeeker]);

    $this->actingAs($jobSeekerWithoutResume)
        ->from(route('jobs.show', $job))
        ->post(route('jobs.apply', $job))
        ->assertRedirect(route('jobs.show', $job))
        ->assertSessionHasErrors(['application' => 'Upload a resume before applying.']);

    $unverified = User::factory()->unverified()->create(['role' => UserRole::JobSeeker]);
    applicationResume($unverified);

    $this->actingAs($unverified)
        ->from(route('jobs.show', $job))
        ->post(route('jobs.apply', $job))
        ->assertRedirect(route('jobs.show', $job))
        ->assertSessionHasErrors(['application' => 'verify your email first']);

    expect(Application::query()->count())->toBe(0);
});

test('submission rejects unavailable jobs and inactive companies or owners', function () {
    $jobSeeker = User::factory()->create(['role' => UserRole::JobSeeker]);
    applicationResume($jobSeeker);
    $activeOwner = applicationOwner();

    $jobs = [
        applicationJob($activeOwner, ['status' => JobStatus::Closed]),
        applicationJob($activeOwner, ['application_deadline' => now()->subDay()]),
        applicationJob($activeOwner, ['status' => JobStatus::Archived]),
        applicationJob(applicationOwner(registrationStatus: RegistrationStatus::PendingApproval)),
        applicationJob(applicationOwner(status: AccountStatus::Suspended)),
    ];

    foreach ($jobs as $job) {
        $this->actingAs($jobSeeker)
            ->from(route('jobs.show', $job))
            ->post(route('jobs.apply', $job))
            ->assertRedirect(route('jobs.show', $job))
            ->assertSessionHasErrors('application');
    }

    expect(Application::query()->count())->toBe(0);
});

test('active applications block reapplication but withdrawn applications do not', function () {
    applicationStorage();
    $jobSeeker = User::factory()->create(['role' => UserRole::JobSeeker]);
    applicationResume($jobSeeker);

    foreach ([ApplicationStatus::Pending, ApplicationStatus::Accepted, ApplicationStatus::Rejected] as $status) {
        $job = applicationJob(applicationOwner());
        existingApplication($jobSeeker, $job, $status);

        $this->actingAs($jobSeeker)
            ->from(route('jobs.show', $job))
            ->post(route('jobs.apply', $job))
            ->assertRedirect(route('jobs.show', $job))
            ->assertSessionHasErrors(['application' => 'You already applied for this job.']);
    }

    $withdrawnJob = applicationJob(applicationOwner());
    existingApplication($jobSeeker, $withdrawnJob, ApplicationStatus::Withdrawn);

    $this->actingAs($jobSeeker)
        ->post(route('jobs.apply', $withdrawnJob))
        ->assertRedirect(route('jobs.show', $withdrawnJob));

    expect($jobSeeker->applications()->where('job_vacancy_id', $withdrawnJob->id)->count())->toBe(2);
});

test('a duplicate created while the resume is copied is caught and the asset is removed', function () {
    $storage = applicationStorage();
    $jobSeeker = User::factory()->create(['role' => UserRole::JobSeeker]);
    applicationResume($jobSeeker);
    $job = applicationJob(applicationOwner());
    $storage->afterCopy = fn () => existingApplication($jobSeeker, $job, ApplicationStatus::Pending);

    $this->actingAs($jobSeeker)
        ->from(route('jobs.show', $job))
        ->post(route('jobs.apply', $job))
        ->assertRedirect(route('jobs.show', $job))
        ->assertSessionHasErrors(['application' => 'You already applied for this job.']);

    expect($storage->deleted)->toBe(['resume-snapshots/'.$storage->copies[0]['name'].'.pdf'])
        ->and(Application::query()->count())->toBe(1)
        ->and(ResumeSnapshot::query()->count())->toBe(0);
});

test('a Cloudinary copy failure creates no application state', function () {
    $storage = applicationStorage();
    $storage->copyFailure = new RuntimeException('Cloudinary unavailable.');
    $jobSeeker = User::factory()->create(['role' => UserRole::JobSeeker]);
    applicationResume($jobSeeker);
    $job = applicationJob(applicationOwner());

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($jobSeeker)->post(route('jobs.apply', $job)))
        ->toThrow(RuntimeException::class, 'Cloudinary unavailable.');

    expect(Application::query()->count())->toBe(0)
        ->and(ResumeSnapshot::query()->count())->toBe(0)
        ->and(ApplicationStatusHistory::query()->count())->toBe(0);
});

test('a database failure after copying removes the snapshot asset', function () {
    $storage = applicationStorage();
    $jobSeeker = User::factory()->create(['role' => UserRole::JobSeeker]);
    applicationResume($jobSeeker);
    $job = applicationJob(applicationOwner());
    Application::creating(fn () => throw new RuntimeException('Database write failed.'));

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($jobSeeker)->post(route('jobs.apply', $job)))
        ->toThrow(RuntimeException::class, 'Database write failed.');

    expect($storage->deleted)->toHaveCount(1)
        ->and(Application::query()->count())->toBe(0)
        ->and(ResumeSnapshot::query()->count())->toBe(0)
        ->and(ApplicationStatusHistory::query()->count())->toBe(0);
});

test('company owners cannot submit applications', function () {
    $owner = applicationOwner();
    $job = applicationJob($owner);

    $this->actingAs($owner)
        ->post(route('jobs.apply', $job))
        ->assertForbidden();

    expect(Application::query()->count())->toBe(0);
});

test('job detail displays eligibility and resume link without conflating saved jobs', function () {
    $jobSeeker = User::factory()->create(['role' => UserRole::JobSeeker]);
    $job = applicationJob(applicationOwner());

    $this->actingAs($jobSeeker)
        ->get(route('jobs.show', $job))
        ->assertOk()
        ->assertSee('Upload a resume before applying.')
        ->assertSee(route('resume.show'));

    applicationResume($jobSeeker);

    expect($jobSeeker->fresh()->resume()->exists())->toBeTrue();

    $this->actingAs($jobSeeker)
        ->get(route('jobs.show', $job))
        ->assertOk()
        ->assertSee('Apply for this job');

    expect(Application::query()->count())->toBe(0);
});
