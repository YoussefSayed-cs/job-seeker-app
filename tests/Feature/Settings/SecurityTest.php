<?php

use App\Livewire\Settings\Security;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Shagalni\Shared\Enums\AccountStatus;
use Shagalni\Shared\Enums\UserRole;
use Shagalni\Shared\Models\User;

beforeEach(function () {});

test('security settings page can be rendered', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('security.edit'));

    $response->assertOk();
});

test('security settings page renders without two factor when feature is disabled', function () {
    config(['fortify.features' => []]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSee('Update password')
        ->assertDontSee('Manage your passkeys for passwordless sign-in')
        ->assertDontSee('Add a passkey to sign in without a password')
        ->assertDontSee('Two-factor authentication');
});

test('password can be updated', function () {
    $user = User::factory()->unverified()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user);

    $response = Livewire::test(Security::class)
        ->set('current_password', 'password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword');

    $response->assertHasNoErrors()
        ->assertSet('current_password', '')
        ->assertSet('password', '')
        ->assertSet('password_confirmation', '');

    $user->refresh();

    expect(Hash::check('new-password', $user->password))->toBeTrue()
        ->and($user->role)->toBe(UserRole::JobSeeker)
        ->and($user->status)->toBe(AccountStatus::Active)
        ->and($user->email_verified_at)->toBeNull();
});

test('correct password must be provided to update password', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user);

    $response = Livewire::test(Security::class)
        ->set('current_password', 'wrong-password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword');

    $response->assertHasErrors(['current_password']);
});
