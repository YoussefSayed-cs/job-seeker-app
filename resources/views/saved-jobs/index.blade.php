<x-layouts::app :title="__('Saved Jobs')">
    <div class="mx-auto w-full max-w-5xl space-y-6">
        <div class="flex items-center justify-between gap-4">
            <div>
                <flux:heading level="1" size="xl">{{ __('Saved Jobs') }}</flux:heading>
                <flux:subheading>{{ __('Jobs you saved to review later.') }}</flux:subheading>
            </div>

            <flux:button :href="route('jobs.search')" variant="outline" wire:navigate>
                {{ __('Browse jobs') }}
            </flux:button>
        </div>

        @if (session('status'))
            <flux:text>{{ session('status') }}</flux:text>
        @endif

        <div class="grid gap-4">
            @forelse ($savedJobs as $savedJob)
                @php
                    $job = $savedJob['job'];
                    $unavailableReasons = [
                        'deleted' => __('This job has been deleted.'),
                        'archived' => __('This job has been archived.'),
                        'closed' => __('This job is closed.'),
                        'expired' => __('The application deadline has passed.'),
                        'company_inactive' => __('This company is not approved.'),
                        'owner_inactive' => __('This company account is inactive.'),
                    ];
                @endphp

                <article class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                        <div class="space-y-1">
                            @if ($job !== null && $savedJob['available'])
                                <flux:link :href="route('jobs.show', $job['id'])" wire:navigate>
                                    <flux:heading level="2" size="lg">{{ $job['title'] }}</flux:heading>
                                </flux:link>
                            @else
                                <flux:heading level="2" size="lg">
                                    {{ $job['title'] ?? __('Deleted job') }}
                                </flux:heading>
                            @endif

                            @if ($job !== null)
                                <flux:text>{{ $job['company']['name'] ?? '' }}</flux:text>
                                <flux:text>{{ $job['location'] }} · {{ $job['employment_type'] }} · {{ $job['work_location_type'] }}</flux:text>
                            @endif

                            @unless ($savedJob['available'])
                                <flux:text class="text-sm text-amber-700 dark:text-amber-300">
                                    {{ $unavailableReasons[$savedJob['unavailable_reason']] ?? __('This job is unavailable.') }}
                                </flux:text>
                            @endunless
                        </div>

                        <form method="POST" action="{{ route('saved-jobs.destroy', $savedJob['id']) }}" class="shrink-0">
                            @csrf
                            @method('DELETE')
                            <flux:button type="submit" variant="ghost">{{ __('Remove') }}</flux:button>
                        </form>
                    </div>
                </article>
            @empty
                <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-700">
                    <flux:text>{{ __('You have no saved jobs yet.') }}</flux:text>
                </div>
            @endforelse
        </div>

        {{ $savedJobs->links() }}
    </div>
</x-layouts::app>
