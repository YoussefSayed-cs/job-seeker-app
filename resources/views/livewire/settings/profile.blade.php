<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-settings.layout :heading="__('Profile')" :subheading="__('Update your full name, email address, and phone number')">
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
