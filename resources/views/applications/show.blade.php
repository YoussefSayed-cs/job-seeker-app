<x-layouts::app :title="$application->job_title">
    <div class="mx-auto w-full max-w-4xl space-y-6">
        <div>
            <flux:link :href="route('applications.index')" wire:navigate>
                {{ __('Back to my applications') }}
            </flux:link>
        </div>

        <article class="space-y-6 rounded-xl border border-zinc-200 p-5 md:p-8 dark:border-zinc-700">
            <header class="space-y-2">
                <flux:heading level="1" size="xl">{{ $application->job_title }}</flux:heading>
                <flux:text>{{ $application->company_name }}</flux:text>
                <flux:text>
                    {{ __('Submitted') }}: {{ $application->submitted_at?->format('M j, Y g:i A') }}
                    · {{ __('Current status') }}: {{ $application->status->value }}
                </flux:text>
            </header>

            <section class="space-y-3">
                <flux:heading level="2" size="lg">{{ __('Status history') }}</flux:heading>
                @if ($application->statusHistories->isNotEmpty())
                    <ol class="space-y-4 border-s border-zinc-200 ps-4 dark:border-zinc-700">
                        @foreach ($application->statusHistories as $history)
                            <li class="space-y-1">
                                <flux:text>
                                    @if ($history->from_status)
                                        {{ $history->from_status->value }} →
                                    @endif
                                    {{ $history->to_status->value }}
                                    · {{ $history->created_at?->format('M j, Y g:i A') }}
                                </flux:text>
                                @if ($history->changedBy)
                                    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $history->changedBy->full_name }}</p>
                                @endif
                                @if ($history->message)
                                    <p class="text-sm text-zinc-700 dark:text-zinc-200">{{ $history->message }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @else
                    <flux:text>{{ __('No status history is available.') }}</flux:text>
                @endif
            </section>

            @if (! $application->status->isTerminal())
                <section class="border-t border-zinc-200 pt-5 dark:border-zinc-700">
                    <form
                        method="POST"
                        action="{{ route('applications.withdraw', $application) }}"
                        class="space-y-3"
                    >
                        @csrf
                        <flux:textarea
                            name="reason"
                            :label="__('Reason (optional)')"
                            maxlength="1000"
                            :value="old('reason')"
                        />
                        @error('reason')
                            <p class="text-sm text-red-600">{{ $message }}</p>
                        @enderror
                        @error('application')
                            <p class="text-sm text-red-600">{{ $message }}</p>
                        @enderror
                        <flux:button type="submit" variant="danger">
                            {{ __('Withdraw application') }}
                        </flux:button>
                    </form>
                </section>
            @endif
        </article>
    </div>
</x-layouts::app>
