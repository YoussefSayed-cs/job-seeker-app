<?php

namespace App\Livewire\Settings;

use App\Concerns\ProfileValidationRules;
use Flux\Flux;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;
use Shagalni\Shared\Models\User;
use Throwable;

#[Title('Profile settings')]
class Profile extends Component
{
    use ProfileValidationRules, WithFileUploads;

    public string $full_name = '';

    public string $email = '';

    public string $phone = '';

    public $profilePicture = null;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        /** @var User $user */
        $user = Auth::user();

        $this->full_name = $user->full_name;
        $this->email = $user->email;
        $this->phone = $user->phone;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        /** @var User $user */
        $user = Auth::user();

        if (config('fortify.lowercase_usernames')) {
            $this->email = Str::lower($this->email);
        }

        $validated = $this->validate($this->profileRules((string) $user->getKey()));

        $user->fill($validated);
        $emailChanged = $user->isDirty('email');

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        Flux::toast(variant: 'success', text: __('Profile updated.'));
    }

    public function updateProfilePicture(): void
    {
        $this->validate([
            'profilePicture' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
            ],
        ]);

        /** @var User $user */
        $user = Auth::user();
        $oldPicture = $user->profile_picture;
        $newPicture = $this->profilePicture->store('profile-pictures', 'public');

        if ($newPicture === false) {
            throw new RuntimeException('Failed to store profile picture.');
        }

        try {
            $user->forceFill([
                'profile_picture' => $newPicture,
            ])->save();
        } catch (Throwable $exception) {
            $this->deleteProfilePictureFile($newPicture);

            throw $exception;
        }

        $this->deleteProfilePictureFile($oldPicture);
        $this->reset('profilePicture');

        Flux::toast(variant: 'success', text: __('Profile picture updated.'));
    }

    public function removeProfilePicture(): void
    {
        /** @var User $user */
        $user = Auth::user();
        $oldPicture = $user->profile_picture;

        if (! $oldPicture) {
            return;
        }

        $user->forceFill([
            'profile_picture' => null,
        ])->save();

        $this->deleteProfilePictureFile($oldPicture);

        Flux::toast(variant: 'success', text: __('Profile picture removed.'));
    }

    private function deleteProfilePictureFile(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path) && ! Storage::disk('public')->delete($path)) {
            report(new RuntimeException('Failed to delete profile picture file.'));
        }
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        $user = Auth::user();

        return $user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        $user = Auth::user();

        return ! $user instanceof MustVerifyEmail || $user->hasVerifiedEmail();
    }
}
