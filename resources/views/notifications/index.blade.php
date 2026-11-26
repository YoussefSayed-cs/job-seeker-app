<x-layouts::app :title="__('Notifications')">
    <div class="mx-auto w-full max-w-5xl space-y-6">
        <div class="flex items-center justify-between gap-4">
            <div>
                <flux:heading level="1" size="xl">{{ __('Notifications') }}</flux:heading>
            </div>

            @if (auth()->user()->unreadNotifications()->exists())
                <form method="POST" action="{{ route('notifications.read-all') }}">
                    @csrf
                    <flux:button type="submit" variant="outline">{{ __('Mark all as read') }}</flux:button>
                </form>
            @endif
        </div>

        <div class="grid gap-4">
            @forelse ($notifications as $notification)
                <article class="rounded-xl border p-5 {{ $notification->read_at ? 'border-zinc-200 dark:border-zinc-700' : 'border-blue-300 bg-blue-50 dark:border-blue-700 dark:bg-blue-950/30' }}">
                    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                        <div class="space-y-2">
                            <flux:text class="{{ $notification->read_at ? '' : 'font-semibold' }}">
                                {{ $notification->data['message'] ?? 'You have a new notification.' }}
                            </flux:text>

                            @isset($notification->data['company_name'])
                                <flux:text class="text-sm">{{ $notification->data['company_name'] }}</flux:text>
                            @endisset

                            @if (filled($notification->data['status_message'] ?? null))
                                <flux:text class="text-sm">
                                    {{ __('Message from the company:') }}
                                </flux:text>
                                <flux:text class="text-sm">{{ $notification->data['status_message'] }}</flux:text>
                            @endif

                            <flux:text class="text-sm">{{ $notification->created_at->diffForHumans() }}</flux:text>
                        </div>

                        @if ($notification->read_at === null)
                            <form method="POST" action="{{ route('notifications.read', $notification->id) }}" class="shrink-0">
                                @csrf
                                <flux:button type="submit" size="sm" variant="ghost">{{ __('Mark as read') }}</flux:button>
                            </form>
                        @endif
                    </div>
                </article>
            @empty
                <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-700">
                    <flux:text>{{ __('No notifications yet.') }}</flux:text>
                </div>
            @endforelse
        </div>

        {{ $notifications->links() }}
    </div>
</x-layouts::app>
