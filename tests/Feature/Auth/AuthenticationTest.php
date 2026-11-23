<?php

use Illuminate\Support\Facades\Auth;
use Shagalni\Shared\Enums\AccountStatus;
use Shagalni\Shared\Models\User;

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

test('unverified users can authenticate and access the dashboard', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticated();

    $this->get(route('dashboard'))->assertOk();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrorsIn('email');

    $this->assertGuest();
});

test('suspended and deactivated users cannot authenticate', function (AccountStatus $status) {
    $user = User::factory()->create(['status' => $status]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrorsIn('email');

    $this->assertGuest();
})->with([
    'suspended' => [AccountStatus::Suspended],
    'deactivated' => [AccountStatus::Deactivated],
]);

test('users with a deletion request cannot authenticate', function () {
    $user = User::factory()->create();
    $user->forceFill(['deletion_requested_at' => now()])->save();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrorsIn('email');

    $this->assertGuest();
});

test('an existing session is terminated when the account becomes inaccessible', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $user->forceFill(['status' => AccountStatus::Suspended])->save();
    Auth::forgetGuards();

    $this->get(route('dashboard'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('anonymized users cannot authenticate', function () {
    $user = User::factory()->create([
        'status' => AccountStatus::Deactivated,
        'anonymized_at' => now(),
    ]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrorsIn('email');

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('home'));

    $this->assertGuest();
});
