<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreApplicationRequest;
use App\Http\Requests\WithdrawApplicationRequest;
use App\Services\ApplicationSubmissionService;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Shagalni\Shared\Enums\ApplicationStatus;
use Shagalni\Shared\Models\Application;
use Shagalni\Shared\Models\ApplicationStatusHistory;
use Shagalni\Shared\Models\JobVacancy;
use Shagalni\Shared\Models\User;
use UnexpectedValueException;

class ApplicationController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', Application::class);

        /** @var User $user */
        $user = request()->user();

        $applications = Application::query()
            ->where('user_id', $user->id)
            ->with([
                'statusHistories' => fn (HasMany $query) => $query->orderBy('created_at')->orderBy('id'),
                'statusHistories.changedBy:id,full_name,role',
            ])
            ->latest('submitted_at')
            ->orderBy('id')
            ->paginate(10);

        return view('applications.index', compact('applications'));
    }

    public function show(Application $application): View
    {
        Gate::authorize('view', $application);

        $application->load([
            'statusHistories' => fn (HasMany $query) => $query->orderBy('created_at')->orderBy('id'),
            'statusHistories.changedBy:id,full_name,role',
        ]);

        return view('applications.show', compact('application'));
    }

    public function store(
        StoreApplicationRequest $request,
        JobVacancy $job,
        ApplicationSubmissionService $submission,
    ): RedirectResponse {
        Gate::authorize('create', [Application::class, $job]);

        /** @var User $user */
        $user = $request->user();
        $submission->submit($user, $job);

        return redirect()
            ->route('jobs.show', $job)
            ->with('status', 'Application submitted successfully.');
    }

    public function withdraw(
        WithdrawApplicationRequest $request,
        Application $application,
    ): RedirectResponse {
        Gate::authorize('withdraw', $application);

        /** @var User $user */
        $user = $request->user();
        $reason = $request->validated('reason');

        DB::transaction(function () use ($application, $user, $reason): void {
            $lockedApplication = Application::query()
                ->lockForUpdate()
                ->findOrFail($application->id);
            $currentStatus = $lockedApplication->getAttribute('status');

            if (! ($currentStatus instanceof ApplicationStatus)) {
                throw new UnexpectedValueException('The application status is invalid.');
            }

            if ($currentStatus->isTerminal()) {
                throw ValidationException::withMessages([
                    'application' => 'This application can no longer be withdrawn.',
                ]);
            }

            $lockedApplication->update([
                'status' => ApplicationStatus::Withdrawn,
            ]);

            ApplicationStatusHistory::query()->create([
                'application_id' => $lockedApplication->id,
                'from_status' => $currentStatus,
                'to_status' => ApplicationStatus::Withdrawn,
                'message' => $reason,
                'changed_by' => $user->id,
            ]);
        });

        return redirect()
            ->route('applications.show', $application)
            ->with('status', 'Application withdrawn successfully.');
    }
}
