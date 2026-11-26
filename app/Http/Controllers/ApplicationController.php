<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreApplicationRequest;
use App\Services\ApplicationSubmissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Shagalni\Shared\Models\Application;
use Shagalni\Shared\Models\JobVacancy;
use Shagalni\Shared\Models\User;

class ApplicationController extends Controller
{
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
}
