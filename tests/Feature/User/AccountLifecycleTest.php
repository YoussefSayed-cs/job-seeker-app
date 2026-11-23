<?php

use App\Livewire\Settings\DeactivateAccountForm;
use App\Livewire\Settings\DeleteUserForm;
use App\Services\AccountLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Shagalni\Shared\Enums\AccountStatus;
use Shagalni\Shared\Enums\RegistrationStatus;
use Shagalni\Shared\Enums\UserRole;
use Shagalni\Shared\Models\Company;
use Shagalni\Shared\Models\User;
use Shagalni\Shared\Notifications\AccountDeletionRequested;
use Shagalni\Shared\Notifications\AccountReactivationRequested;

test('a job seeker can deactivate their account after confirming their password', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(DeactivateAccountForm::class)
        ->set('password', 'password')
        ->call('deactivate')
        ->assertHasNoErrors();

    expect($user->fresh()->status)->toBe(AccountStatus::Deactivated);
    $this->assertGuest();
});

test('deactivation requires the current password', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(DeactivateAccountForm::class)
        ->set('password', 'incorrect')
        ->call('deactivate')
        ->assertHasErrors(['password']);

    expect($user->fresh()->status)->toBe(AccountStatus::Active);
});

test('account deletion records a request and queues a secure cancellation link', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(DeleteUserForm::class)
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->deletion_requested_at)->not->toBeNull()
        ->and($user->status)->toBe(AccountStatus::Active);
    $this->assertGuest();

    Notification::assertSentTo($user, AccountDeletionRequested::class, function (AccountDeletionRequested $notification) use ($user): bool {
        return URL::hasValidSignature(
            Request::create($notification->toMail($user)->actionUrl)
        );
    });
});

test('a user can cancel a deletion request through its signed email link', function () {
    $user = User::factory()->create(['deletion_requested_at' => now()]);
    $url = (new AccountDeletionRequested)->toMail($user)->actionUrl;

    $this->get($url)
        ->assertRedirect(route('login'))
        ->assertSessionHas('status');

    expect($user->fresh()->deletion_requested_at)->toBeNull()
        ->and($user->fresh()->status)->toBe(AccountStatus::Active);
});

test('deletion cancellation fails at the exact 30-day cutoff', function () {
    $requestedAt = now()->startOfSecond();
    $user = User::factory()->create(['deletion_requested_at' => $requestedAt]);
    $service = app(AccountLifecycleService::class);
    $url = URL::temporarySignedRoute(
        'account-deletion.cancel',
        $requestedAt->addDays(AccountLifecycleService::DELETION_GRACE_DAYS)->addDay(),
        [
            'user' => $user->getRouteKey(),
            'requested' => $requestedAt->getTimestamp(),
        ]
    );

    $this->travelTo($requestedAt->addDays(AccountLifecycleService::DELETION_GRACE_DAYS));

    expect(fn () => $service->cancelDeletion($user, $requestedAt->getTimestamp()))
        ->toThrow(ValidationException::class);

    $this->get($url)
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'The account recovery period has expired.');
});

test('a deactivated job seeker can request and consume a single-use reactivation link', function () {
    Notification::fake();

    $user = User::factory()->create(['status' => AccountStatus::Deactivated]);

    $this->post(route('account-reactivation.store'), ['email' => $user->email])
        ->assertSessionHas('status');

    $url = null;
    Notification::assertSentTo($user, AccountReactivationRequested::class, function (AccountReactivationRequested $notification) use ($user, &$url): bool {
        $url = $notification->toMail($user)->actionUrl;

        return true;
    });

    expect($url)->not->toBeNull();

    $this->get($url)->assertRedirect(route('login'));
    expect($user->fresh()->status)->toBe(AccountStatus::Active);

    $this->get($url)->assertRedirect(route('login'));
    expect($user->fresh()->status)->toBe(AccountStatus::Active);
});

test('reactivation requests use a generic response and do not send links to suspended or anonymized accounts', function () {
    Notification::fake();

    $suspended = User::factory()->create(['status' => AccountStatus::Suspended]);
    $anonymized = User::factory()->create([
        'status' => AccountStatus::Deactivated,
        'anonymized_at' => now(),
    ]);

    foreach ([$suspended->email, $anonymized->email, 'unknown@example.com'] as $email) {
        $this->post(route('account-reactivation.store'), ['email' => $email])
            ->assertSessionHas('status');
    }

    Notification::assertNothingSent();
});

test('only job seekers and pending or rejected company owners can use Job Seeker Portal lifecycle self-service', function () {
    $service = app(AccountLifecycleService::class);
    $jobSeeker = User::factory()->create();
    $pendingOwner = User::factory()->create(['role' => UserRole::CompanyOwner]);
    $approvedOwner = User::factory()->create(['role' => UserRole::CompanyOwner]);

    foreach ([
        [$pendingOwner, RegistrationStatus::PendingApproval],
        [$approvedOwner, RegistrationStatus::Approved],
    ] as [$owner, $status]) {
        Company::unguarded(fn () => Company::create([
            'owner_id' => $owner->id,
            'name' => 'Lifecycle Company',
            'industry' => 'Software',
            'location' => 'Cairo',
            'registration_status' => $status,
        ]));
    }

    expect($service->canManageFromJobSeekerPortal($jobSeeker))->toBeTrue()
        ->and($service->canManageFromJobSeekerPortal($pendingOwner))->toBeTrue()
        ->and($service->canManageFromJobSeekerPortal($approvedOwner))->toBeFalse();

    $this->actingAs($approvedOwner);

    Livewire::test(DeleteUserForm::class)
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasErrors(['password']);

    expect($approvedOwner->fresh()->deletion_requested_at)->toBeNull();
});
