<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Shagalni\Shared\Enums\AccountStatus;
use Shagalni\Shared\Enums\ApplicationStatus;
use Shagalni\Shared\Enums\JobStatus;
use Shagalni\Shared\Enums\RegistrationStatus;
use Shagalni\Shared\Enums\UserRole;
use Shagalni\Shared\Events\ApplicationSubmitted;
use Shagalni\Shared\Models\Application;
use Shagalni\Shared\Models\ApplicationStatusHistory;
use Shagalni\Shared\Models\JobVacancy;
use Shagalni\Shared\Models\Resume;
use Shagalni\Shared\Models\ResumeSnapshot;
use Shagalni\Shared\Models\User;
use Throwable;
use UnexpectedValueException;

class ApplicationSubmissionService
{
    public function __construct(private ResumeStorageService $storage) {}

    public function ineligibilityReason(User $user, JobVacancy $job): ?string
    {
        if ($user->role !== UserRole::JobSeeker) {
            return 'Only job seekers can apply.';
        }

        if ($user->status !== AccountStatus::Active || $user->deletion_requested_at !== null) {
            return 'Your account is not eligible to apply.';
        }

        if (! $user->hasVerifiedEmail()) {
            return 'verify your email first';
        }

        if (! $user->resume()->exists()) {
            return 'Upload a resume before applying.';
        }

        $jobReason = $this->jobIneligibilityReason($job);

        if ($jobReason !== null) {
            return $jobReason;
        }

        if ($this->hasActiveApplication($user, $job)) {
            return 'You already applied for this job.';
        }

        return null;
    }

    public function submit(User $user, JobVacancy $job): Application
    {
        $this->assertEligible($user, $job);

        /** @var Resume $resume */
        $resume = $user->resume()->firstOrFail();
        $resumeFilePath = $resume->getAttribute('file_path');
        $resumeOriginalName = $resume->getAttribute('original_name');

        if (! is_string($resumeFilePath) || ! is_string($resumeOriginalName)) {
            throw new UnexpectedValueException('The current resume metadata is invalid.');
        }

        $applicationId = (string) Str::uuid();
        $snapshotPath = $this->storage->copy(
            $resumeFilePath,
            'resume-snapshots',
            $applicationId,
        );

        try {
            $application = DB::transaction(function () use ($user, $job, $resumeOriginalName, $applicationId, $snapshotPath): Application {
                $lockedJob = JobVacancy::query()
                    ->lockForUpdate()
                    ->with(['company.owner'])
                    ->findOrFail($job->id);
                $currentUser = User::query()->findOrFail($user->id);

                $this->assertEligible($currentUser, $lockedJob);

                $application = new Application;
                $application->forceFill([
                    'id' => $applicationId,
                    'job_vacancy_id' => $lockedJob->id,
                    'company_id' => $lockedJob->company->id,
                    'user_id' => $currentUser->id,
                    'status' => ApplicationStatus::Pending,
                    'job_title' => $lockedJob->title,
                    'company_name' => $lockedJob->company->name,
                    'submitted_at' => now(),
                ]);
                $application->save();

                ResumeSnapshot::query()->create([
                    'application_id' => $application->id,
                    'file_path' => $snapshotPath,
                    'original_name' => $resumeOriginalName,
                ]);

                ApplicationStatusHistory::query()->create([
                    'application_id' => $application->id,
                    'from_status' => null,
                    'to_status' => ApplicationStatus::Pending,
                    'changed_by' => $currentUser->id,
                ]);

                return $application;
            });
        } catch (Throwable $exception) {
            try {
                $this->storage->delete($snapshotPath);
            } catch (Throwable $cleanupException) {
                report($cleanupException);
            }

            throw $exception;
        }

        event(new ApplicationSubmitted($application));

        return $application;
    }

    private function assertEligible(User $user, JobVacancy $job): void
    {
        $reason = $this->ineligibilityReason($user, $job);

        if ($reason !== null) {
            throw ValidationException::withMessages([
                'application' => $reason,
            ]);
        }
    }

    private function jobIneligibilityReason(JobVacancy $job): ?string
    {
        if ($job->status === JobStatus::Archived || $job->archived_at !== null) {
            return 'This job is archived.';
        }

        if ($job->status === JobStatus::Closed) {
            return 'This job is closed.';
        }

        if ($job->isExpired()) {
            return 'The application deadline has passed.';
        }

        $company = $job->company;

        if ($company === null || $company->registration_status !== RegistrationStatus::Approved) {
            return 'This job is not available because its company is not approved.';
        }

        if ($company->owner === null || $company->owner->status !== AccountStatus::Active) {
            return 'This job is not available because its owner account is inactive.';
        }

        if (! $job->isAcceptingApplications()) {
            return 'This job is not accepting applications.';
        }

        return null;
    }

    private function hasActiveApplication(User $user, JobVacancy $job): bool
    {
        return $user->applications()
            ->where('job_vacancy_id', $job->id)
            ->where('status', '!=', ApplicationStatus::Withdrawn->value)
            ->exists();
    }
}
