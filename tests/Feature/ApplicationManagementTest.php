<?php

use Illuminate\Support\Facades\Event;
use Shagalni\Shared\Enums\AccountStatus;
use Shagalni\Shared\Enums\AiAnalysisStatus;
use Shagalni\Shared\Enums\ApplicationStatus;
use Shagalni\Shared\Enums\RegistrationStatus;
use Shagalni\Shared\Enums\UserRole;
use Shagalni\Shared\Events\ApplicationWithdrawn;
use Shagalni\Shared\Models\AiAnalysis;
use Shagalni\Shared\Models\Application;
use Shagalni\Shared\Models\ApplicationStatusHistory;
use Shagalni\Shared\Models\Company;
use Shagalni\Shared\Models\User;

function myApplicationsCompany(): array
{
    $owner = User::factory()->create([
        'role' => UserRole::CompanyOwner,
        'status' => AccountStatus::Active,
    ]);

    $company = Company::unguarded(fn () => Company::create([
        'owner_id' => $owner->id,
        'name' => 'Applications Test Company',
        'industry' => 'Technology',
        'location' => 'Cairo',
        'registration_status' => RegistrationStatus::Approved,
    ]));

    return [$owner, $company];
}

function myApplication(
    User $applicant,
    ApplicationStatus $status = ApplicationStatus::Pending,
    ?string $jobTitle = null,
): array {
    [$owner, $company] = myApplicationsCompany();

    $application = Application::query()->create([
        'job_vacancy_id' => null,
        'company_id' => $company->id,
        'user_id' => $applicant->id,
        'status' => $status,
        'job_title' => $jobTitle ?? 'Snapshot Job '.fake()->unique()->numerify('###'),
        'company_name' => $company->name,
        'submitted_at' => now(),
    ]);

    return [$application, $owner];
}

test('the application list is newest first, paginated, and scoped to the signed-in seeker', function () {
    $jobSeeker = User::factory()->create(['role' => UserRole::JobSeeker]);
    $otherSeeker = User::factory()->create(['role' => UserRole::JobSeeker]);
    $ownApplications = [];

    foreach (range(1, 12) as $index) {
        [$application] = myApplication($jobSeeker, jobTitle: 'Own application '.$index);
        $application->forceFill(['submitted_at' => now()->subMinutes($index)])->save();
        $ownApplications[] = $application;
    }

    myApplication($otherSeeker, jobTitle: 'Other seeker application');

    $this->actingAs($jobSeeker)
        ->get(route('applications.index'))
        ->assertOk()
        ->assertSee('Own application 1')
        ->assertDontSee('Other seeker application')
        ->assertDontSee('AI_SECRET_FEEDBACK')
        ->assertSee('Next');

    $response = $this->actingAs($jobSeeker)
        ->get(route('applications.index', ['page' => 2]))
        ->assertOk()
        ->assertSee('Own application 11')
        ->assertDontSee('Other seeker application');

    expect($response->viewData('applications')->total())->toBe(12)
        ->and($response->viewData('applications')->perPage())->toBe(10)
        ->and($response->viewData('applications')->first()->id)->toBe($ownApplications[10]->id);
});

test('application detail uses snapshot values, shows owner history, and never shows AI data', function () {
    $jobSeeker = User::factory()->create(['role' => UserRole::JobSeeker]);
    [$application, $owner] = myApplication($jobSeeker, jobTitle: 'Snapshot-only job title');

    ApplicationStatusHistory::query()->create([
        'application_id' => $application->id,
        'from_status' => ApplicationStatus::Pending,
        'to_status' => ApplicationStatus::UnderReview,
        'message' => 'Company owner update message.',
        'changed_by' => $owner->id,
    ]);

    AiAnalysis::query()->create([
        'application_id' => $application->id,
        'status' => AiAnalysisStatus::Completed,
        'match_score' => 99,
        'feedback' => 'AI_SECRET_FEEDBACK',
        'attempts' => 1,
    ]);

    $this->actingAs($jobSeeker)
        ->get(route('applications.show', $application))
        ->assertOk()
        ->assertSee('Snapshot-only job title')
        ->assertSee('Applications Test Company')
        ->assertSee('Company owner update message.')
        ->assertDontSee('AI_SECRET_FEEDBACK')
        ->assertDontSee('AI analysis');
});

test('a job seeker cannot view or withdraw another users application', function () {
    $owner = User::factory()->create(['role' => UserRole::JobSeeker]);
    $other = User::factory()->create(['role' => UserRole::JobSeeker]);
    [$application] = myApplication($owner);

    $this->actingAs($other)
        ->get(route('applications.show', $application))
        ->assertForbidden();

    $this->actingAs($other)
        ->post(route('applications.withdraw', $application))
        ->assertForbidden();

    expect($application->fresh()->status)->toBe(ApplicationStatus::Pending);
});

test('company owners and admins cannot access seeker application routes', function () {
    $jobSeeker = User::factory()->create(['role' => UserRole::JobSeeker]);
    [$application] = myApplication($jobSeeker);
    $owner = User::factory()->create(['role' => UserRole::CompanyOwner]);
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    foreach ([$owner, $admin] as $unauthorizedUser) {
        $this->actingAs($unauthorizedUser)
            ->get(route('applications.index'))
            ->assertForbidden();

        $this->actingAs($unauthorizedUser)
            ->get(route('applications.show', $application))
            ->assertForbidden();

        $this->actingAs($unauthorizedUser)
            ->post(route('applications.withdraw', $application))
            ->assertForbidden();
    }
});

test('a seeker can withdraw with a reason and the observer dispatches ApplicationWithdrawn', function () {
    Event::fake([ApplicationWithdrawn::class]);
    $jobSeeker = User::factory()->create(['role' => UserRole::JobSeeker]);
    [$application] = myApplication($jobSeeker, ApplicationStatus::Interview);

    $this->actingAs($jobSeeker)
        ->post(route('applications.withdraw', $application), [
            'reason' => 'I have accepted another opportunity.',
        ])
        ->assertRedirect(route('applications.show', $application))
        ->assertSessionHas('status', 'Application withdrawn successfully.');

    $history = ApplicationStatusHistory::query()
        ->where('application_id', $application->id)
        ->firstOrFail();

    expect($application->fresh()->status)->toBe(ApplicationStatus::Withdrawn)
        ->and($history->from_status)->toBe(ApplicationStatus::Interview)
        ->and($history->to_status)->toBe(ApplicationStatus::Withdrawn)
        ->and($history->message)->toBe('I have accepted another opportunity.')
        ->and($history->changed_by)->toBe($jobSeeker->id);

    Event::assertDispatched(ApplicationWithdrawn::class, fn (ApplicationWithdrawn $event): bool => $event->history->is($history));
});

test('a seeker can withdraw without a reason', function () {
    $jobSeeker = User::factory()->create(['role' => UserRole::JobSeeker]);
    [$application] = myApplication($jobSeeker, ApplicationStatus::Shortlisted);

    $this->actingAs($jobSeeker)
        ->post(route('applications.withdraw', $application))
        ->assertRedirect(route('applications.show', $application));

    expect(ApplicationStatusHistory::query()
        ->where('application_id', $application->id)
        ->firstOrFail()
        ->message)->toBeNull();
});

test('terminal applications cannot be withdrawn', function (ApplicationStatus $status) {
    $jobSeeker = User::factory()->create(['role' => UserRole::JobSeeker]);
    [$application] = myApplication($jobSeeker, $status);

    $this->actingAs($jobSeeker)
        ->from(route('applications.show', $application))
        ->post(route('applications.withdraw', $application))
        ->assertRedirect(route('applications.show', $application))
        ->assertSessionHasErrors('application');

    expect($application->fresh()->status)->toBe($status)
        ->and(ApplicationStatusHistory::query()->where('application_id', $application->id)->count())->toBe(0);
})->with([
    'accepted' => ApplicationStatus::Accepted,
    'rejected' => ApplicationStatus::Rejected,
    'withdrawn' => ApplicationStatus::Withdrawn,
]);

test('applications with deleted jobs still render from their snapshot fields', function () {
    $jobSeeker = User::factory()->create(['role' => UserRole::JobSeeker]);
    [$application] = myApplication($jobSeeker, jobTitle: 'Deleted job snapshot title');

    $this->actingAs($jobSeeker)
        ->get(route('applications.show', $application))
        ->assertOk()
        ->assertSee('Deleted job snapshot title')
        ->assertSee('Applications Test Company');
});
