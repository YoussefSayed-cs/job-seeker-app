<?php

use App\Services\ResumeStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Shagalni\Shared\Enums\AccountStatus;
use Shagalni\Shared\Enums\ResumeFileType;
use Shagalni\Shared\Enums\UserRole;
use Shagalni\Shared\Models\Resume;
use Shagalni\Shared\Models\User;
use Tests\Fakes\FakeResumeStorageService;

function resumeStorageFake(): FakeResumeStorageService
{
    $storage = new FakeResumeStorageService;
    app()->instance(ResumeStorageService::class, $storage);

    return $storage;
}

function resumePdf(string $name = 'resume.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\nResume test content\n%%EOF");
}

function resumeRecord(User $user, array $attributes = []): Resume
{
    return Resume::create(array_merge([
        'user_id' => $user->id,
        'file_path' => 'resumes/'.$user->id.'/old.pdf',
        'original_name' => 'old.pdf',
        'file_size' => 128,
        'file_type' => ResumeFileType::Pdf,
    ], $attributes));
}

test('an active job seeker can upload a resume', function () {
    $storage = resumeStorageFake();
    $user = User::factory()->create();
    $file = resumePdf();

    $this->actingAs($user)
        ->from(route('resume.show'))
        ->post(route('resume.store'), ['file' => $file])
        ->assertRedirect(route('resume.show'))
        ->assertSessionHas('status');

    $resume = $user->resume()->firstOrFail();

    expect($resume->file_path)->toStartWith('resumes/'.$user->id.'/')
        ->and($resume->original_name)->toBe('resume.pdf')
        ->and($resume->file_size)->toBe($file->getSize())
        ->and($resume->file_type)->toBe(ResumeFileType::Pdf)
        ->and($storage->uploads)->toHaveCount(1)
        ->and($storage->uploads[0]['folder'])->toBe('resumes/'.$user->id)
        ->and($storage->deleted)->toBeEmpty();
});

test('an active job seeker can upload a DOCX resume', function () {
    $storage = resumeStorageFake();
    $user = User::factory()->create();
    $file = UploadedFile::fake()->create(
        'resume.docx',
        1,
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    );

    $this->actingAs($user)
        ->from(route('resume.show'))
        ->post(route('resume.store'), ['file' => $file])
        ->assertRedirect(route('resume.show'));

    expect($user->resume()->firstOrFail()->file_type)->toBe(ResumeFileType::Docx)
        ->and($storage->uploads)->toHaveCount(1);
});

test('upload replaces the current resume and deletes only the old asset after saving', function () {
    $storage = resumeStorageFake();
    $user = User::factory()->create();
    $existing = resumeRecord($user);
    $file = resumePdf('updated-resume.pdf');

    $this->actingAs($user)
        ->from(route('resume.show'))
        ->post(route('resume.store'), ['file' => $file])
        ->assertRedirect(route('resume.show'));

    $existing->refresh();

    expect(Resume::where('user_id', $user->id)->count())->toBe(1)
        ->and($existing->file_path)->toStartWith('resumes/'.$user->id.'/')
        ->and($existing->original_name)->toBe('updated-resume.pdf')
        ->and($storage->deleted)->toBe(['resumes/'.$user->id.'/old.pdf']);
});

test('resume files larger than 5 MB are rejected', function () {
    $storage = resumeStorageFake();
    $user = User::factory()->create();
    $file = UploadedFile::fake()->create('large.pdf', 5121, 'application/pdf');

    $this->actingAs($user)
        ->from(route('resume.show'))
        ->post(route('resume.store'), ['file' => $file])
        ->assertSessionHasErrors('file');

    expect($user->resume()->exists())->toBeFalse()
        ->and($storage->uploads)->toBeEmpty();
});

test('files with an unsupported content type are rejected', function () {
    $storage = resumeStorageFake();
    $user = User::factory()->create();
    $file = UploadedFile::fake()->create('resume.pdf', 1, 'text/plain');

    $this->actingAs($user)
        ->from(route('resume.show'))
        ->post(route('resume.store'), ['file' => $file])
        ->assertSessionHasErrors('file');

    expect($user->resume()->exists())->toBeFalse()
        ->and($storage->uploads)->toBeEmpty();
});

test('a PDF with a DOCX extension is rejected by server-side MIME validation', function () {
    $storage = resumeStorageFake();
    $user = User::factory()->create();
    $file = UploadedFile::fake()->create('resume.docx', 1, 'application/pdf');

    $this->actingAs($user)
        ->from(route('resume.show'))
        ->post(route('resume.store'), ['file' => $file])
        ->assertSessionHasErrors('file');

    expect($user->resume()->exists())->toBeFalse()
        ->and($storage->uploads)->toBeEmpty();
});

test('a database write failure deletes the new asset and preserves the current resume', function () {
    $storage = resumeStorageFake();
    $user = User::factory()->create();
    $existing = resumeRecord($user);
    $file = resumePdf('replacement.pdf');

    Event::listen('eloquent.saving: '.Resume::class, function (): never {
        throw new RuntimeException('Simulated resume write failure.');
    });

    $this->actingAs($user)
        ->from(route('resume.show'))
        ->post(route('resume.store'), ['file' => $file])
        ->assertRedirect(route('resume.show'))
        ->assertSessionHasErrors('file');

    $existing->refresh();

    expect($existing->file_path)->toBe('resumes/'.$user->id.'/old.pdf')
        ->and($storage->uploads)->toHaveCount(1)
        ->and($storage->deleted)->toBe([$storage->uploads[0]['folder'].'/'.$storage->uploads[0]['name'].'.pdf']);
});

test('only active job seekers may access resume routes', function (UserRole $role, AccountStatus $status) {
    $storage = resumeStorageFake();
    $user = User::factory()->create(['role' => $role, 'status' => $status]);

    $this->actingAs($user)
        ->get(route('resume.show'))
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('resume.store'), ['file' => resumePdf()])
        ->assertForbidden();

    $this->actingAs($user)
        ->get(route('resume.download'))
        ->assertForbidden();

    expect($storage->uploads)->toBeEmpty();
})->with([
    'company owner' => [UserRole::CompanyOwner, AccountStatus::Active],
    'admin' => [UserRole::Admin, AccountStatus::Active],
]);

test('inactive job seekers are denied by the resume policy', function () {
    $user = User::factory()->create(['status' => AccountStatus::Suspended]);

    expect($user->can('viewAny', Resume::class))->toBeFalse()
        ->and($user->can('create', Resume::class))->toBeFalse();
});

test('guests must authenticate before accessing resume routes', function () {
    $this->get(route('resume.show'))->assertRedirect(route('login'));
    $this->post(route('resume.store'), ['file' => resumePdf()])->assertRedirect(route('login'));
    $this->get(route('resume.download'))->assertRedirect(route('login'));
});

test('a job seeker cannot view or update another users resume', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $resume = resumeRecord($owner);

    expect($otherUser->can('view', $resume))->toBeFalse()
        ->and($otherUser->can('update', $resume))->toBeFalse();
});

test('a job seeker cannot download another users resume or change their record', function () {
    $storage = resumeStorageFake();
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $resume = resumeRecord($owner);

    $this->actingAs($otherUser)
        ->get(route('resume.download'))
        ->assertNotFound();

    $this->actingAs($otherUser)
        ->from(route('resume.show'))
        ->post(route('resume.store'), [
            'file' => resumePdf('other-user.pdf'),
            'user_id' => $owner->id,
        ])
        ->assertRedirect(route('resume.show'));

    expect($resume->fresh()->file_path)->toBe('resumes/'.$owner->id.'/old.pdf')
        ->and($otherUser->resume()->exists())->toBeTrue()
        ->and($storage->signedUrls)->toBeEmpty()
        ->and($storage->deleted)->toBeEmpty();
});

test('download redirects to a signed URL for the authenticated users resume', function () {
    $storage = resumeStorageFake();
    $user = User::factory()->create();
    $resume = resumeRecord($user);

    $this->actingAs($user)
        ->get(route('resume.download'))
        ->assertRedirect('https://cloudinary.test/'.$resume->file_path);

    expect($storage->signedUrls)->toBe([
        ['public_id' => $resume->file_path, 'seconds' => 300],
    ]);
});

test('the resume page shows only the authenticated users resume', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    resumeRecord($otherUser, ['original_name' => 'private-resume.pdf']);

    $this->actingAs($user)
        ->get(route('resume.show'))
        ->assertOk()
        ->assertSee('You have not uploaded a resume yet.')
        ->assertDontSee('private-resume.pdf');
});
