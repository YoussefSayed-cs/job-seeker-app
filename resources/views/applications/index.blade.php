<x-layouts::app :title="__('My Applications')">
    <div class="mx-auto w-full max-w-5xl space-y-6">
        <div>
            <flux:heading level="1" size="xl">{{ __('My Applications') }}</flux:heading>
            <flux:subheading>{{ __('Review your applications and their status history.') }}</flux:subheading>
        </div>

        <div class="grid gap-4">
            @forelse ($applications as $application)
                <article class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                    <a href="{{ route('applications.show', $application) }}" class="block space-y-1">
                        <flux:heading level="2" size="lg">{{ $application->job_title }}</flux:heading>
                        <flux:text>{{ $application->company_name }}</flux:text>
                    </a>
                    <flux:text>
                        {{ __('Submitted') }}: {{ $application->submitted_at?->format('M j, Y g:i A') }}
                        · {{ __('Status') }}: {{ $application->status->value }}
                    </flux:text>

                    @if ($application->statusHistories->isNotEmpty())
                        <ol class="space-y-2 border-s border-zinc-200 ps-4 dark:border-zinc-700">
                            @foreach ($application->statusHistories as $history)
                                <li class="space-y-1">
                                    <flux:text>
                                        {{ $history->to_status->value }}
                                        · {{ $history->created_at?->format('M j, Y g:i A') }}
                                        @if ($history->changedBy)
                                            · {{ $history->changedBy->full_name }}
                                        @endif
                                    </flux:text>
                                    @if ($history->message)
                                        <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ $history->message }}</p>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </article>
            @empty
                <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-700">
                    <flux:text>{{ __('You have not submitted any applications yet.') }}</flux:text>
                </div>
            @endforelse
        </div>

        {{ $applications->links() }}
    </div>
</x-layouts::app>
