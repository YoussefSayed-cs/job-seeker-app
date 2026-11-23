<div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
    <div class="space-y-1">
        <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ __('Request account deletion') }}</h3>
        <p class="text-sm text-slate-500 dark:text-slate-400">
            {{ __('Your account will be scheduled for anonymization after a 30-day grace period. You can cancel using the link sent to your email.') }}
        </p>
    </div>

    <flux:modal.trigger name="confirm-user-deletion">
        <flux:button variant="danger" x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')">
            {{ __('Delete account') }}
        </flux:button>
    </flux:modal.trigger>

    <flux:modal name="confirm-user-deletion" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
        <form wire:submit="deleteUser" class="space-y-6">
            <div>
                <flux:heading size="lg">
                    {{ __('Are you sure you want to request account deletion?') }}
                </flux:heading>

                <flux:subheading>
                    {{ __('Your account will be inaccessible immediately. Its identifying information will be anonymized after 30 days unless you cancel the request from the email link.') }}
                </flux:subheading>
            </div>

            <flux:input wire:model="password" :label="__('Current password')" type="password" autocomplete="current-password" viewable required />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" type="submit">
                    {{ __('Confirm deletion request') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
