<?php

use App\Livewire\Settings\Profile;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
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

test('profile picture can be uploaded and replaced', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(Profile::class)
        ->set('profilePicture', UploadedFile::fake()->image('first.jpg'))
        ->call('updateProfilePicture')
        ->assertHasNoErrors();

    $first = $user->refresh()->profile_picture;

    expect($first)->not->toBeNull();
    Storage::disk('public')->assertExists($first);

    Livewire::test(Profile::class)
        ->set('profilePicture', UploadedFile::fake()->image('second.png'))
        ->call('updateProfilePicture')
        ->assertHasNoErrors();

    $second = $user->refresh()->profile_picture;

    expect($second)->not->toBe($first);
    Storage::disk('public')->assertExists($second);
    Storage::disk('public')->assertMissing($first);
});

test('profile picture rejects invalid file types and files over the size limit', function () {
    Storage::fake('public');

    $this->actingAs(User::factory()->create());

    Livewire::test(Profile::class)
        ->set('profilePicture', UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf'))
        ->call('updateProfilePicture')
        ->assertHasErrors(['profilePicture']);

    Livewire::test(Profile::class)
        ->set('profilePicture', UploadedFile::fake()->image('too-large.jpg')->size(2049))
        ->call('updateProfilePicture')
        ->assertHasErrors(['profilePicture']);
});

test('profile picture can be removed', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(Profile::class)
        ->set('profilePicture', UploadedFile::fake()->image('avatar.jpg'))
        ->call('updateProfilePicture');

    $path = $user->refresh()->profile_picture;

    Livewire::test(Profile::class)
        ->call('removeProfilePicture')
        ->assertHasNoErrors();

    expect($user->refresh()->profile_picture)->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

test('changing email sends a verification link to the new address', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(Profile::class)
        ->set('full_name', $user->full_name)
        ->set('email', 'updated@example.com')
        ->set('phone', $user->phone)
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($user->fresh()->email_verified_at)->toBeNull();

    Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user): bool {
        $url = $notification->toMail($user)->actionUrl;

        return str_ends_with(
            (string) parse_url($url, PHP_URL_PATH),
            '/'.sha1('updated@example.com'),
        );
    });
});

test('verification link for the previous email is invalid after an email change', function () {
    $user = User::factory()->create();
    $previousEmail = $user->email;
    $oldVerificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($previousEmail)],
    );

    $this->actingAs($user);

    Livewire::test(Profile::class)
        ->set('full_name', $user->full_name)
        ->set('email', 'updated@example.com')
        ->set('phone', $user->phone)
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $this->get($oldVerificationUrl)->assertForbidden();

    expect($user->fresh()->email)->toBe('updated@example.com')
        ->and($user->fresh()->hasVerifiedEmail())->toBeFalse();
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

test('profile updates allow a phone number shared with another account', function () {
    User::factory()->create(['phone' => '01011112222']);
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(Profile::class)
        ->set('full_name', $user->full_name)
        ->set('email', $user->email)
        ->set('phone', '01011112222')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($user->fresh()->phone)->toBe('01011112222');
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

test('account deletion requires password confirmation and never deletes the user', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('settings.delete-user-form')
        ->set('password', 'incorrect')
        ->call('deleteUser')
        ->assertHasErrors(['password']);

    expect($user->fresh())->not->toBeNull()
        ->and($user->fresh()->status)->toBe(AccountStatus::Active)
        ->and($user->fresh()->deletion_requested_at)->toBeNull()
        ->and(auth()->check())->toBeTrue();
});
