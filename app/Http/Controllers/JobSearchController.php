<?php

namespace App\Http\Controllers;

use App\Http\Requests\JobSearchRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Shagalni\Shared\Models\JobCategory;
use Shagalni\Shared\Models\JobVacancy;

class JobSearchController extends Controller
{
    public function index(JobSearchRequest $request): JsonResponse|View
    {
        Gate::authorize('viewAny', JobVacancy::class);

        $paginator = JobVacancy::query()
            ->publiclyVisible()
            ->searchFilters($request->validated())
            ->with([
                'company:id,name,logo',
                'category:id,name',
            ])
            ->latest('created_at')
            ->orderBy('id')
            ->paginate(10)
            ->withQueryString();

        if (! $request->expectsJson()) {
            $categories = JobCategory::query()
                ->orderBy('name')
                ->get(['id', 'name']);

            return view('jobs.index', [
                'jobs' => $paginator,
                'categories' => $categories,
                'filters' => $request->validated(),
            ]);
        }

        $paginator->getCollection()->transform(fn (JobVacancy $job): array => [
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
        ]);

        return response()->json($paginator);
    }

    public function show(JobVacancy $job): View
    {
        Gate::authorize('viewAny', JobVacancy::class);

        $job = JobVacancy::query()
            ->publiclyVisible()
            ->with([
                'company:id,name,description,industry,location,website,logo',
                'category:id,name',
            ])
            ->findOrFail($job->id);

        Gate::authorize('view', $job);

        return view('jobs.show', [
            'job' => $job,
        ]);
    }
}
