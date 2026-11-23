<x-layouts::app :title="$job->title">
    <div class="mx-auto w-full max-w-4xl space-y-6">
        <div>
            <flux:link :href="route('jobs.search')" wire:navigate>
                {{ __('Back to jobs') }}
            </flux:link>
        </div>

        <article class="space-y-6 rounded-xl border border-zinc-200 p-5 md:p-8 dark:border-zinc-700">
            <header class="space-y-2">
                <flux:heading level="1" size="xl">{{ $job->title }}</flux:heading>
                <flux:text>{{ $job->company?->name }} · {{ $job->location }}</flux:text>
                <flux:text>{{ $job->employment_type->value }} · {{ $job->work_location_type->value }}</flux:text>
            </header>

            <section class="grid gap-4 md:grid-cols-2">
                <div>
                    <flux:heading level="2" size="lg">{{ __('Job details') }}</flux:heading>
                    <dl class="mt-3 grid grid-cols-1 gap-3">
                        <div>
                            <dt class="text-sm text-zinc-500">{{ __('Category') }}</dt>
                            <dd>{{ $job->category?->name ?? __('Not specified') }}</dd>
                        </div>
                        @if ($job->required_skills)
                            <div>
                                <dt class="text-sm text-zinc-500">{{ __('Required skills') }}</dt>
                                <dd class="whitespace-pre-line">{{ $job->required_skills }}</dd>
                            </div>
                        @endif
                        @if ($job->salary_min !== null || $job->salary_max !== null)
                            <div>
                                <dt class="text-sm text-zinc-500">{{ __('Salary range') }}</dt>
                                <dd>
                                    @if ($job->salary_min !== null)
                                        {{ $job->salary_min }}
                                    @endif
                                    @if ($job->salary_min !== null && $job->salary_max !== null)
                                        –
                                    @endif
                                    @if ($job->salary_max !== null)
                                        {{ $job->salary_max }}
                                    @endif
                                </dd>
                            </div>
                        @endif
                        @if ($job->experience_level)
                            <div>
                                <dt class="text-sm text-zinc-500">{{ __('Experience level') }}</dt>
                                <dd>{{ $job->experience_level->value }}</dd>
                            </div>
                        @endif
                        @if ($job->application_deadline)
                            <div>
                                <dt class="text-sm text-zinc-500">{{ __('Application deadline') }}</dt>
                                <dd>{{ $job->application_deadline->format('M j, Y') }}</dd>
                            </div>
                        @endif
                        <div>
                            <dt class="text-sm text-zinc-500">{{ __('Published') }}</dt>
                            <dd>{{ $job->created_at?->format('M j, Y') ?? __('Not specified') }}</dd>
                        </div>
                    </dl>
                </div>

                <div>
                    <flux:heading level="2" size="lg">{{ __('Company') }}</flux:heading>
                    <dl class="mt-3 grid grid-cols-1 gap-3">
                        @if ($job->company?->logo)
                            <div>
                                <img
                                    src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($job->company->logo) }}"
                                    alt="{{ $job->company->name }}"
                                    class="max-h-20 max-w-40 object-contain"
                                >
                            </div>
                        @endif
                        <div>
                            <dt class="text-sm text-zinc-500">{{ __('Name') }}</dt>
                            <dd>{{ $job->company?->name ?? __('Not specified') }}</dd>
                        </div>
                        @if ($job->company?->location)
                            <div>
                                <dt class="text-sm text-zinc-500">{{ __('Location') }}</dt>
                                <dd>{{ $job->company->location }}</dd>
                            </div>
                        @endif
                        @if ($job->company?->description)
                            <div>
                                <dt class="text-sm text-zinc-500">{{ __('About') }}</dt>
                                <dd class="whitespace-pre-line">{{ $job->company->description }}</dd>
                            </div>
                        @endif
                        @if ($job->company?->website)
                            <div>
                                <dt class="text-sm text-zinc-500">{{ __('Website') }}</dt>
                                <dd>
                                    <a href="{{ $job->company->website }}" target="_blank" rel="noopener noreferrer" class="underline">
                                        {{ $job->company->website }}
                                    </a>
                                </dd>
                            </div>
                        @endif
                    </dl>
                </div>
            </section>

            <section class="space-y-5">
                <div>
                    <flux:heading level="2" size="lg">{{ __('Description') }}</flux:heading>
                    <div class="mt-2 whitespace-pre-line">{{ $job->description }}</div>
                </div>
                <div>
                    <flux:heading level="2" size="lg">{{ __('Requirements') }}</flux:heading>
                    <div class="mt-2 whitespace-pre-line">{{ $job->requirements }}</div>
                </div>
            </section>
        </article>
    </div>
</x-layouts::app>
