<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreResumeRequest;
use App\Services\ResumeStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Shagalni\Shared\Enums\ResumeFileType;
use Shagalni\Shared\Models\Resume;
use Shagalni\Shared\Models\User;
use Throwable;
use UnexpectedValueException;

class ResumeController extends Controller
{
    public function __construct(private ResumeStorageService $storage) {}

    public function show(Request $request): View
    {
        Gate::authorize('viewAny', Resume::class);

        $user = $this->authenticatedUser($request);
        /** @var Resume|null $resume */
        $resume = $user->resume()->first();

        if ($resume !== null) {
            Gate::authorize('view', $resume);
        }

        return view('resume.show', compact('resume'));
    }

    public function store(StoreResumeRequest $request): RedirectResponse
    {
        Gate::authorize('create', Resume::class);

        $user = $this->authenticatedUser($request);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);

        /** @var Resume|null $existing */
        $existing = $user->resume()->first();

        if ($existing !== null) {
            Gate::authorize('update', $existing);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $fileType = ResumeFileType::tryFrom($extension);
        abort_unless($fileType !== null, 422);

        $newFilePath = $this->storage->upload(
            $file,
            "resumes/{$user->id}",
            (string) Str::uuid(),
        );

        try {
            $user->resume()->updateOrCreate([], [
                'file_path' => $newFilePath,
                'original_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'file_type' => $fileType,
            ]);
        } catch (Throwable $exception) {
            try {
                $this->storage->delete($newFilePath);
            } catch (Throwable $cleanupException) {
                report($cleanupException);
            }

            report($exception);

            return back()->withErrors([
                'file' => 'We could not save your resume. Please try again.',
            ]);
        }

        if ($existing !== null) {
            $oldFilePath = $this->filePath($existing);

            if ($oldFilePath !== $newFilePath) {
                $this->storage->delete($oldFilePath);
            }
        }

        return redirect()
            ->route('resume.show')
            ->with('status', 'Resume uploaded successfully.');
    }

    public function download(Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Resume::class);

        $user = $this->authenticatedUser($request);
        /** @var Resume $resume */
        $resume = $user->resume()->firstOrFail();
        Gate::authorize('view', $resume);

        return redirect()->away($this->storage->signedUrl($this->filePath($resume)));
    }

    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function filePath(Resume $resume): string
    {
        $filePath = $resume->getAttribute('file_path');

        if (! is_string($filePath)) {
            throw new UnexpectedValueException('The resume file path must be a string.');
        }

        return $filePath;
    }
}
