<x-layouts::auth :title="__('Reactivate account')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Reactivate your account')" :description="__('Enter the email address associated with your account to request a reactivation link.')" />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('account-reactivation.store') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="email@example.com"
            />

            <flux:button variant="primary" type="submit" class="w-full">
                {{ __('Email reactivation link') }}
            </flux:button>
        </form>

        <div class="text-center text-sm">
            <flux:link :href="route('login')" wire:navigate>{{ __('Return to log in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
