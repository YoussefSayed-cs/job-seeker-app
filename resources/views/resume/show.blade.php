<x-layouts::app :title="__('My Resume')">
    <div class="mx-auto w-full max-w-3xl space-y-6">
        <div>
            <flux:heading level="1" size="xl">{{ __('My Resume') }}</flux:heading>
            <flux:subheading>{{ __('Keep your resume up to date for job applications.') }}</flux:subheading>
        </div>

        @if (session('status'))
            <p role="status" class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200">
                {{ session('status') }}
            </p>
        @endif

        @if ($resume)
            <section class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <div>
                    <flux:heading level="2" size="lg">{{ __('Current resume') }}</flux:heading>
                    <flux:text>{{ $resume->original_name }}</flux:text>
                </div>

                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ __('File size') }}</dt>
                        <dd class="mt-1 text-zinc-900 dark:text-zinc-100">{{ number_format($resume->file_size / 1024, 1) }} KB</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Uploaded') }}</dt>
                        <dd class="mt-1 text-zinc-900 dark:text-zinc-100">{{ $resume->updated_at?->format('M j, Y') }}</dd>
                    </div>
                </dl>

                <flux:button :href="route('resume.download')" variant="outline" icon="arrow-down-tray">
                    {{ __('Download resume') }}
                </flux:button>
            </section>
        @else
            <p class="rounded-xl border border-dashed border-zinc-300 p-5 text-sm text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">
                {{ __('You have not uploaded a resume yet.') }}
            </p>
        @endif

        <section class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <div>
                <flux:heading level="2" size="lg">{{ $resume ? __('Replace resume') : __('Upload resume') }}</flux:heading>
                <flux:text>{{ __('PDF or DOCX, up to 5 MB.') }}</flux:text>
            </div>

            <form method="POST" action="{{ route('resume.store') }}" enctype="multipart/form-data" class="space-y-4">
                @csrf

                <div>
                    <label for="resume-file" class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-200">
                        {{ __('Resume file') }}
                    </label>
                    <input
                        id="resume-file"
                        name="file"
                        type="file"
                        accept=".pdf,.docx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                        required
                        class="block w-full text-sm text-zinc-700 file:me-4 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-zinc-800 hover:file:bg-zinc-200 dark:text-zinc-200 dark:file:bg-zinc-700 dark:file:text-zinc-100 dark:hover:file:bg-zinc-600"
                    >
                    @error('file')
                        <p role="alert" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <flux:button type="submit" variant="primary">
                    {{ $resume ? __('Replace resume') : __('Upload resume') }}
                </flux:button>
            </form>
        </section>
    </div>
</x-layouts::app>