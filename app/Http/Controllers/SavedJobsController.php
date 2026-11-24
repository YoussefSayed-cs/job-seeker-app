<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavedJobRequest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Shagalni\Shared\Enums\AccountStatus;
use Shagalni\Shared\Enums\JobStatus;
use Shagalni\Shared\Enums\RegistrationStatus;
use Shagalni\Shared\Models\JobVacancy;
use Shagalni\Shared\Models\SavedJob;
use Shagalni\Shared\Models\User;

class SavedJobsController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', SavedJob::class);

        /** @var User $user */
        $user = request()->user();

        $paginator = $user->savedJobs()
            ->with([
                'jobVacancy.company.owner',
                'jobVacancy.category:id,name',
            ])
            ->latest('created_at')
            ->orderBy('id')
            ->paginate(10)
            ->withQueryString();

        $paginator->getCollection()->transform(fn (SavedJob $savedJob): array => $this->savedJobPayload($savedJob)
        );

        return response()->json($paginator);
    }

    public function store(SavedJobRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', SavedJob::class);

        /** @var User $user */
        $user = $request->user();

        $job = JobVacancy::query()
            ->with(['company.owner', 'category:id,name'])
            ->findOrFail($request->validated('job_id'));

        Gate::authorize('view', $job);

        $existing = $user->savedJobs()
            ->with(['jobVacancy.company.owner', 'jobVacancy.category:id,name'])
            ->where('job_vacancy_id', $job->id)
            ->first();

        if ($existing !== null) {
            return response()->json([
                'message' => 'Job is already saved.',
                'saved_job' => $this->savedJobPayload($existing),
            ]);
        }

        if (! $job->isAcceptingApplications()) {
            throw ValidationException::withMessages([
                'job_id' => 'This job is not available for bookmarking.',
            ]);
        }

        try {
            $savedJob = DB::transaction(fn () => $user->savedJobs()->create([
                'job_vacancy_id' => $job->id,
            ]));

            return response()->json([
                'message' => 'Job saved successfully.',
                'saved_job' => $this->savedJobPayload($savedJob->load('jobVacancy.company.owner', 'jobVacancy.category:id,name')),
            ], 201);
        } catch (UniqueConstraintViolationException) {
            $savedJob = $user->savedJobs()
                ->with(['jobVacancy.company.owner', 'jobVacancy.category:id,name'])
                ->where('job_vacancy_id', $job->id)
                ->firstOrFail();

            return response()->json([
                'message' => 'Job is already saved.',
                'saved_job' => $this->savedJobPayload($savedJob),
            ]);
        }
    }

    public function show(SavedJob $savedJob): JsonResponse
    {
        Gate::authorize('view', $savedJob);

        $savedJob->load([
            'jobVacancy.company.owner',
            'jobVacancy.category:id,name',
        ]);

        return response()->json([
            'saved_job' => $this->savedJobPayload($savedJob),
        ]);
    }

    public function destroy(SavedJob $savedJob): JsonResponse
    {
        Gate::authorize('delete', $savedJob);

        $savedJob->delete();

        return response()->json([
            'message' => 'Saved job removed successfully.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function savedJobPayload(SavedJob $savedJob): array
    {
        $job = $savedJob->jobVacancy;

        if ($job === null) {
            return [
                'id' => $savedJob->id,
                'created_at' => $savedJob->created_at?->toIso8601String(),
                'job' => null,
                'available' => false,
                'unavailable_reason' => 'deleted',
            ];
        }

        $reason = $this->unavailableReason($job);

        return [
            'id' => $savedJob->id,
            'created_at' => $savedJob->created_at?->toIso8601String(),
            'job' => [
                'id' => $job->id,
                'title' => $job->title,
                'location' => $job->location,
                'employment_type' => $job->employment_type->value,
                'work_location_type' => $job->work_location_type->value,
                'salary_min' => $job->salary_min,
                'salary_max' => $job->salary_max,
                'experience_level' => $job->experience_level?->value,
                'application_deadline' => $job->application_deadline?->toDateString(),
                'company' => [
                    'name' => $job->company?->name,
                    'logo' => $job->company?->logo,
                ],
                'category' => [
                    'name' => $job->category?->name,
                ],
                'created_at' => $job->created_at?->toIso8601String(),
            ],
            'available' => $reason === null,
            'unavailable_reason' => $reason,
        ];
    }

    private function unavailableReason(JobVacancy $job): ?string
    {
        if ($job->status === JobStatus::Archived || $job->archived_at !== null) {
            return 'archived';
        }

        if ($job->status === JobStatus::Closed) {
            return 'closed';
        }

        if ($job->isExpired()) {
            return 'expired';
        }

        $company = $job->company;

        if ($company === null || $company->registration_status !== RegistrationStatus::Approved) {
            return 'company_inactive';
        }

        $owner = $company->owner;

        if ($owner === null || $owner->status !== AccountStatus::Active) {
            return 'owner_inactive';
        }

        return null;
    }
}
