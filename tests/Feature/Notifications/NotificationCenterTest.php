<?php

use Illuminate\Support\Str;
use Shagalni\Shared\Enums\AccountStatus;
use Shagalni\Shared\Enums\UserRole;
use Shagalni\Shared\Models\User;

function notificationCenterUser(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'role' => UserRole::JobSeeker,
        'status' => AccountStatus::Active,
    ], $attributes));
}

test('guest is redirected to login for notifications', function () {
    $this->get('/notifications')
        ->assertRedirect(route('login'));
});

test('index shows only the current users notifications', function () {
    $user = notificationCenterUser();
    $otherUser = notificationCenterUser();

    $ownNotification = $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'application_status_changed',
        'data' => [
            'message' => 'Your application was accepted.',
            'company_name' => 'Own Company',
            'application_id' => (string) Str::uuid(),
        ],
    ]);

    $otherUser->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'application_status_changed',
        'data' => [
            'message' => 'Another user notification.',
            'company_name' => 'Other Company',
            'application_id' => (string) Str::uuid(),
        ],
    ]);

    $this->actingAs($user)
        ->get('/notifications')
        ->assertOk()
        ->assertSee('Your application was accepted.')
        ->assertSee('Own Company')
        ->assertDontSee('Another user notification.')
        ->assertDontSee('Other Company');

    expect($ownNotification->fresh()->read_at)->toBeNull();
});

test('markAsRead marks own notification as read', function () {
    $user = notificationCenterUser();
    $notification = $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'application_status_changed',
        'data' => [
            'message' => 'Your application status changed.',
            'company_name' => 'Acme',
            'application_id' => (string) Str::uuid(),
        ],
    ]);

    $this->actingAs($user)
        ->post(route('notifications.read', $notification->id))
        ->assertRedirect(route('notifications.index'));

    expect($notification->fresh()->read_at)->not->toBeNull();
});

test('markAsRead on another users notification returns 404 and stays unread', function () {
    $user = notificationCenterUser();
    $otherUser = notificationCenterUser();
    $notification = $otherUser->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'application_status_changed',
        'data' => [
            'message' => 'Another users status changed.',
            'company_name' => 'Acme',
            'application_id' => (string) Str::uuid(),
        ],
    ]);

    $this->actingAs($user)
        ->post(route('notifications.read', $notification->id))
        ->assertNotFound();

    expect($notification->fresh()->read_at)->toBeNull();
});

test('markAllAsRead marks only the current users notifications', function () {
    $user = notificationCenterUser();
    $otherUser = notificationCenterUser();

    $ownNotification = $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'application_status_changed',
        'data' => [
            'message' => 'Your application status changed.',
            'company_name' => 'Acme',
            'application_id' => (string) Str::uuid(),
        ],
    ]);
    $otherNotification = $otherUser->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'application_status_changed',
        'data' => [
            'message' => 'Another users status changed.',
            'company_name' => 'Acme',
            'application_id' => (string) Str::uuid(),
        ],
    ]);

    $this->actingAs($user)
        ->post(route('notifications.read-all'))
        ->assertRedirect()
        ->assertSessionHas('status', 'All notifications marked as read.');

    expect($ownNotification->fresh()->read_at)->not->toBeNull()
        ->and($otherNotification->fresh()->read_at)->toBeNull();
});

test('unknown notification type without message still renders', function () {
    $user = notificationCenterUser();
    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'unknown_notification_type',
        'data' => [
            'company_name' => 'Acme',
        ],
    ]);

    $this->actingAs($user)
        ->get('/notifications')
        ->assertOk()
        ->assertSee('You have a new notification.')
        ->assertSee('Acme');
});
