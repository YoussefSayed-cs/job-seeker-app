<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResubmitCompanyRegistrationRequest;
use App\Services\ResubmitCompanyRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Shagalni\Shared\Models\Company;
use Shagalni\Shared\Models\User;

class RejectedCompanyRegistrationController extends Controller
{
    public function __construct(
        private ResubmitCompanyRegistrationService $resubmissionService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        /** @var Company|null $company */
        $company = $user->company()->first();

        abort_if($company === null, 403);
        Gate::authorize('viewRejectedRegistration', $company);

        return response()->json([
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'description' => $company->description,
                'industry' => $company->industry,
                'location' => $company->location,
                'website' => $company->website,
                'logo' => $company->logo,
                'registration_status' => $company->registration_status->value,
                'rejection_reason' => $company->rejection_reason,
            ],
        ]);
    }

    public function update(ResubmitCompanyRegistrationRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        /** @var Company|null $company */
        $company = $user->company()->first();

        abort_if($company === null, 403);
        Gate::authorize('resubmitRegistration', $company);

        try {
            $company = $this->resubmissionService->resubmit(
                $request->user(),
                $request->validated(),
            );
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => 'The Company registration cannot be resubmitted.',
                'errors' => $exception->errors(),
            ], 422);
        }

        return response()->json([
            'message' => 'The Company registration was resubmitted.',
            'company' => [
                'id' => $company->id,
                'registration_status' => $company->registration_status->value,
            ],
        ]);
    }
}
