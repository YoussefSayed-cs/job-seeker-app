<div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
    <div class="space-y-1">
        <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ __('Deactivate account') }}</h3>
        <p class="text-sm text-slate-500 dark:text-slate-400">
            {{ __('Temporarily deactivate your account. You can request a reactivation link from the sign-in page.') }}
        </p>
    </div>

    <flux:modal.trigger name="confirm-account-deactivation">
        <flux:button variant="outline" x-on:click.prevent="$dispatch('open-modal', 'confirm-account-deactivation')">
            {{ __('Deactivate account') }}
        </flux:button>
    </flux:modal.trigger>

    <flux:modal name="confirm-account-deactivation" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
        <form wire:submit="deactivate" class="space-y-6">
            <div>
                <flux:heading size="lg">
                    {{ __('Are you sure you want to deactivate your account?') }}
                </flux:heading>

                <flux:subheading>
                    {{ __('You will be signed out and will need a reactivation link before you can sign in again.') }}
                </flux:subheading>
            </div>

            <flux:input wire:model="password" :label="__('Current password')" type="password" autocomplete="current-password" viewable required />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" type="submit">
                    {{ __('Confirm deactivation') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
