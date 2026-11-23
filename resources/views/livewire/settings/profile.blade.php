<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-settings.layout :heading="__('Profile')" :subheading="__('Manage your personal information and profile picture')">
        @php
            $user = auth()->user();
        @endphp

        <div class="my-6 flex flex-col gap-4 sm:flex-row sm:items-center" x-data>
            <input
                x-ref="profilePicture"
                type="file"
                wire:model="profilePicture"
                accept="image/png,image/jpeg,image/webp"
                class="hidden"
            >

            <div class="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-full bg-slate-100 text-sm font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                @if ($profilePicture?->isPreviewable())
                    <img src="{{ $profilePicture->temporaryUrl() }}" alt="{{ __('Profile picture preview') }}" class="size-full object-cover">
                @elseif ($user->profile_picture_url)
                    <img src="{{ $user->profile_picture_url }}" alt="{{ __('Profile picture') }}" class="size-full object-cover">
                @else
                    {{ collect(explode(' ', $user->full_name))->filter()->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->implode('') }}
                @endif
            </div>

            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    @if ($profilePicture)
                        <flux:button
                            type="button"
                            variant="primary"
                            wire:click="updateProfilePicture"
                            wire:loading.attr="disabled"
                            wire:target="updateProfilePicture"
                        >
                            {{ __('Save photo') }}
                        </flux:button>
                        <flux:button type="button" variant="ghost" x-on:click="$wire.profilePicture = null; $refs.profilePicture.value = ''">
                            {{ __('Cancel') }}
                        </flux:button>
                    @else
                        <flux:button type="button" variant="outline" x-on:click="$refs.profilePicture.click()">
                            {{ __('Change photo') }}
                        </flux:button>

                        @if ($user->profile_picture)
                            <flux:button
                                type="button"
                                variant="ghost"
                                wire:click="removeProfilePicture"
                                wire:confirm="{{ __('Are you sure you want to remove your profile picture?') }}"
                                wire:loading.attr="disabled"
                                wire:target="removeProfilePicture"
                            >
                                {{ __('Remove') }}
                            </flux:button>
                        @endif
                    @endif
                </div>

                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ __('JPG, PNG or WebP, max 2 MB') }}</p>
                @error('profilePicture')
                    <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
                <p wire:loading wire:target="profilePicture" class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                    {{ __('Preparing preview...') }}
                </p>
            </div>
        </div>

        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="full_name" :label="__('Full name')" type="text" required autofocus autocomplete="name" />

            <div>
                <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />
            </div>

            <flux:input wire:model="phone" :label="__('Phone number')" type="tel" required autocomplete="tel" />

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            </div>
        </form>

        @if ($this->hasUnverifiedEmail)
            <div class="mt-4">
                <flux:text>
                    {{ __('Your email address is unverified.') }}
                </flux:text>

                <form method="POST" action="{{ route('verification.send') }}" class="mt-2">
                    @csrf
                    <flux:button type="submit" variant="ghost" class="text-sm">
                        {{ __('Click here to re-send the verification email.') }}
                    </flux:button>
                </form>
            </div>
        @endif

        @if ($this->showDeleteUser)
            <livewire:settings.delete-user-form />
        @endif
    </x-settings.layout>
</section>
