<?php

use App\Livewire\Settings\Profile;
use Livewire\Livewire;
use Shagalni\Shared\Enums\AccountStatus;
use Shagalni\Shared\Enums\UserRole;
use Shagalni\Shared\Models\User;

test('profile page is displayed', function () {
    $this->actingAs($user = User::factory()->create());

    $this->get('/settings/profile')->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test(Profile::class)
        ->set('full_name', 'Test User')
        ->set('email', 'test@example.com')
        ->set('phone', '01098765432')
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    $user->refresh();

    expect($user->full_name)->toEqual('Test User');
    expect($user->email)->toEqual('test@example.com');
    expect($user->phone)->toEqual('01098765432');
    expect($user->email_verified_at)->toBeNull();
});

test('email is stored in lowercase so the user can still log in', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(Profile::class)
        ->set('full_name', 'Test User')
        ->set('email', 'Test@Example.com')
        ->set('phone', $user->phone)
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($user->refresh()->email)->toEqual('test@example.com');

    $this->post(route('logout'));

    $this->post(route('login.store'), [
        'email' => 'Test@Example.com',
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);
});

test('email must be unique regardless of case', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(Profile::class)
        ->set('full_name', 'Test User')
        ->set('email', 'Taken@Example.com')
        ->set('phone', $user->phone)
        ->call('updateProfileInformation')
        ->assertHasErrors(['email']);
});

test('email verification status is unchanged when email address is unchanged', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test(Profile::class)
        ->set('full_name', 'Test User')
        ->set('email', $user->email)
        ->set('phone', $user->phone)
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('profile updates reject invalid phone numbers without changing the saved number', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(Profile::class)
        ->set('full_name', $user->full_name)
        ->set('email', $user->email)
        ->set('phone', str_repeat('1', 21))
        ->call('updateProfileInformation')
        ->assertHasErrors(['phone']);

    expect($user->fresh()->phone)->toBe($user->phone);
});

test('profile updates cannot change protected account fields', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(Profile::class)
        ->set('full_name', 'Updated Name')
        ->set('email', $user->email)
        ->set('phone', $user->phone)
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $updatedUser = $user->fresh();

    expect($updatedUser->role)->toBe(UserRole::JobSeeker)
        ->and($updatedUser->status)->toBe(AccountStatus::Active)
        ->and($updatedUser->deletion_requested_at)->toBeNull()
        ->and($updatedUser->anonymized_at)->toBeNull();
});

test('account deletion endpoint is disabled and never deletes the user', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('settings.delete-user-form')
        ->call('deleteUser')
        ->assertForbidden();

    expect($user->fresh())->not->toBeNull()
        ->and(auth()->check())->toBeTrue();
});
