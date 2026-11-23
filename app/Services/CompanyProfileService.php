<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Shagalni\Shared\Enums\RegistrationStatus;
use Shagalni\Shared\Models\Company;
use Throwable;

class CompanyProfileService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Company $company, array $data): Company
    {
        $logo = $data['logo'] ?? null;
        unset($data['logo']);

        $attributes = [];
        foreach (['description', 'location', 'website'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        $oldLogo = $company->logo;
        $newLogoPath = null;

        if ($logo instanceof UploadedFile) {
            $newLogoPath = Storage::disk('public')
                ->putFile('company-logos', $logo);

            if (! $newLogoPath) {
                throw new RuntimeException('Unable to upload company logo.');
            }

            $attributes['logo'] = $newLogoPath;
        }

        try {
            DB::transaction(function () use ($company, $attributes): void {
                $locked = Company::query()
                    ->lockForUpdate()
                    ->findOrFail($company->id);

                if (
                    $locked->owner_id !== $company->owner_id
                    || $locked->registration_status !== RegistrationStatus::Approved
                ) {
                    throw new RuntimeException('Company profile is no longer eligible for direct updates.');
                }

                $saved = $locked->update($attributes);

                if (! $saved) {
                    throw new RuntimeException('Unable to persist company profile changes.');
                }
            });
        } catch (Throwable $exception) {
            if ($newLogoPath) {
                Storage::disk('public')->delete($newLogoPath);
            }

            throw $exception;
        }

        if ($newLogoPath && $oldLogo) {
            Storage::disk('public')->delete($oldLogo);
        }

        return $company->refresh();
    }
}
