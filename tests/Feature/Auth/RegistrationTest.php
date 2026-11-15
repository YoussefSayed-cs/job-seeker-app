<?php

use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Features;
use Shagalni\Shared\Enums\AccountStatus;
use Shagalni\Shared\Enums\UserRole;
use Shagalni\Shared\Models\User;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'full_name' => 'John Doe',
        'email' => 'test@example.com',
        'phone' => '01012345678',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role' => UserRole::Admin->value,
    ]);

    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();

    $user = User::where('email', 'test@example.com')->firstOrFail();

    expect($user->full_name)->toBe('John Doe')
        ->and($user->phone)->toBe('01012345678')
        ->and($user->role)->toBe(UserRole::JobSeeker)
        ->and($user->status)->toBe(AccountStatus::Active)
        ->and($user->email_verified_at)->toBeNull()
        ->and(Hash::check('password', $user->password))->toBeTrue();
});

test('phone is required for registration', function () {
    $this->post(route('register.store'), [
        'full_name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors(['phone']);
});
