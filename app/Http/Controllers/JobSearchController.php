<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Shagalni\Shared\Models\JobVacancy;

class JobSearchController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', JobVacancy::class);

        $paginator = JobVacancy::query()
            ->publiclyVisible()
            ->with([
                'company:id,name,logo',
                'category:id,name',
            ])
            ->latest('created_at')
            ->orderBy('id')
            ->paginate(10)
            ->withQueryString();

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
}
