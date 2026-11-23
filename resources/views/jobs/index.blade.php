<x-layouts::app :title="__('Jobs')">
    <div class="mx-auto w-full max-w-5xl space-y-6">
        <div>
            <flux:heading level="1" size="xl">{{ __('Jobs') }}</flux:heading>
            <flux:subheading>{{ __('Browse published job opportunities.') }}</flux:subheading>
        </div>

        <form method="GET" action="{{ route('jobs.search') }}" class="grid gap-3 rounded-xl border border-zinc-200 p-4 md:grid-cols-[1fr_220px_220px_auto] dark:border-zinc-700">
            <flux:input
                name="title"
                :label="__('Job title')"
                :value="$filters['title'] ?? ''"
                type="search"
            />

            <flux:select name="job_category_id" :label="__('Category')">
                <option value="">{{ __('All categories') }}</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected(($filters['job_category_id'] ?? null) === $category->id)>
                        {{ $category->name }}
                    </option>
                @endforeach
            </flux:select>

            <flux:input
                name="location"
                :label="__('Location')"
                :value="$filters['location'] ?? ''"
                type="search"
            />

            <div class="flex items-end">
                <flux:button type="submit" variant="primary">{{ __('Search') }}</flux:button>
            </div>
        </form>

        <div class="grid gap-4">
            @forelse ($jobs as $job)
                <a
                    href="{{ route('jobs.show', $job) }}"
                    class="block rounded-xl border border-zinc-200 p-5 transition hover:border-zinc-400 dark:border-zinc-700 dark:hover:border-zinc-500"
                    wire:navigate
                >
                    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                        <div class="space-y-1">
                            <flux:heading level="2" size="lg">{{ $job->title }}</flux:heading>
                            <flux:text>{{ $job->company?->name }}</flux:text>
                            <flux:text>{{ $job->location }} · {{ $job->employment_type->value }} · {{ $job->work_location_type->value }}</flux:text>
                        </div>
                        <flux:text class="shrink-0">{{ $job->created_at?->format('M j, Y') }}</flux:text>
                    </div>
                </a>
            @empty
                <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-700">
                    <flux:text>{{ __('No matching jobs found.') }}</flux:text>
                </div>
            @endforelse
        </div>

        {{ $jobs->links() }}
    </div>
</x-layouts::app>
