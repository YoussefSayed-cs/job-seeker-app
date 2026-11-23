<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCompanyProfileRequest;
use App\Services\CompanyProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Shagalni\Shared\Enums\UserRole;
use Shagalni\Shared\Models\Company;
use Shagalni\Shared\Models\User;

class CompanyProfileController extends Controller
{
    public function __construct(
        private CompanyProfileService $profileService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $company = $this->ownedApprovedCompany($request);

        Gate::authorize('viewOwnProfile', $company);

        return response()->json([
            'company' => $this->profilePayload($company),
        ]);
    }

    public function update(UpdateCompanyProfileRequest $request): JsonResponse
    {
        $company = $this->ownedApprovedCompany($request);

        Gate::authorize('updateProfile', $company);

        $company = $this->profileService->update(
            $company,
            $request->validated(),
        );

        return response()->json([
            'message' => 'Company profile updated successfully.',
            'company' => $this->profilePayload($company),
        ]);
    }

    private function ownedApprovedCompany(Request $request): Company
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);
        abort_unless($user->role === UserRole::CompanyOwner, 403);

        /** @var Company|null $company */
        $company = $user->company()->first();

        abort_unless($company !== null, 404);

        return $company;
    }

    /**
     * @return array<string, mixed>
     */
    private function profilePayload(Company $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'description' => $company->description,
            'industry' => $company->industry,
            'location' => $company->location,
            'website' => $company->website,
            'logo' => $company->logo,
            'registration_status' => $company->registration_status->value,
        ];
    }
}
