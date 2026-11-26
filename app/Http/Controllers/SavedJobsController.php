<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavedJobRequest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Shagalni\Shared\Enums\AccountStatus;
use Shagalni\Shared\Enums\JobStatus;
use Shagalni\Shared\Enums\RegistrationStatus;
use Shagalni\Shared\Models\JobVacancy;
use Shagalni\Shared\Models\SavedJob;
use Shagalni\Shared\Models\User;

class SavedJobsController extends Controller
{
    public function index(Request $request): JsonResponse|View
    {
        Gate::authorize('viewAny', SavedJob::class);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $paginator = SavedJob::query()
            ->where('user_id', $user->id)
            ->with([
                'jobVacancy.company.owner',
                'jobVacancy.category:id,name',
            ])
            ->latest('created_at')
            ->orderBy('id')
            ->paginate(10)
            ->withQueryString();

        if (! $request->expectsJson()) {
            return view('saved-jobs.index', [
                'savedJobs' => $paginator->through(fn (SavedJob $savedJob): array => $this->savedJobPayload($savedJob)),
            ]);
        }

        $paginator->getCollection()->transform(fn (SavedJob $savedJob): array => $this->savedJobPayload($savedJob));

        return response()->json($paginator);
    }

    public function store(SavedJobRequest $request): JsonResponse|RedirectResponse
    {
        Gate::authorize('viewAny', SavedJob::class);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $jobId = $request->validated('job_id');
        $job = JobVacancy::query()
            ->with(['company.owner', 'category:id,name'])
            ->whereKey($jobId)
            ->firstOrFail();

        Gate::authorize('view', $job);

        $existing = SavedJob::query()
            ->where('user_id', $user->id)
            ->with(['jobVacancy.company.owner', 'jobVacancy.category:id,name'])
            ->where('job_vacancy_id', $job->id)
            ->first();

        if ($existing !== null) {
            if (! $request->expectsJson()) {
                return back()->with('status', 'Job is already saved.');
            }

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
            $savedJob = DB::transaction(fn (): SavedJob => SavedJob::query()->create([
                'user_id' => $user->id,
                'job_vacancy_id' => $job->id,
            ]));

            if (! $request->expectsJson()) {
                return redirect()->route('saved-jobs.index')->with('status', 'Job saved successfully.');
            }

            return response()->json([
                'message' => 'Job saved successfully.',
                'saved_job' => $this->savedJobPayload($savedJob->load('jobVacancy.company.owner', 'jobVacancy.category:id,name')),
            ], 201);
        } catch (UniqueConstraintViolationException) {
            $savedJob = SavedJob::query()
                ->where('user_id', $user->id)
                ->with(['jobVacancy.company.owner', 'jobVacancy.category:id,name'])
                ->where('job_vacancy_id', $job->id)
                ->firstOrFail();

            if (! $request->expectsJson()) {
                return back()->with('status', 'Job is already saved.');
            }

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

    public function destroy(Request $request, SavedJob $savedJob): JsonResponse|RedirectResponse
    {
        Gate::authorize('delete', $savedJob);

        $savedJob->delete();

        if (! $request->expectsJson()) {
            return redirect()->route('saved-jobs.index')->with('status', 'Saved job removed successfully.');
        }

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
        $createdAt = $savedJob->getAttribute('created_at');

        if ($job === null) {
            return [
                'id' => $savedJob->id,
                'created_at' => $createdAt instanceof Carbon ? $createdAt->toIso8601String() : null,
                'job' => null,
                'available' => false,
                'unavailable_reason' => 'deleted',
            ];
        }

        if (! $job instanceof JobVacancy) {
            throw new \UnexpectedValueException('The saved job relationship returned an invalid model.');
        }

        $reason = $this->unavailableReason($job);

        return [
            'id' => $savedJob->id,
            'created_at' => $createdAt instanceof Carbon ? $createdAt->toIso8601String() : null,
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
