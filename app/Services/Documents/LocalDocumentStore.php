<?php

namespace App\Services\Documents;

use App\Models\Application;
use App\Models\Requirement;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Private disk (storage/app/private). Never served by a public URL: files
 * are streamed through authorized routes only. Names are random.
 */
class LocalDocumentStore implements DocumentStore
{
    public function __construct(private string $disk) {}

    public function driver(): string
    {
        return 'local';
    }

    public function put(UploadedFile $file, Application $application, Requirement $requirement, ?string $previousRef = null): StoredFile
    {
        $name = Str::random(40).'.'.strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
        $path = Storage::disk($this->disk)->putFileAs("documents/{$application->number}", $file, $name);
        if ($path === false) {
            throw new RuntimeException('The file could not be stored.');
        }

        return new StoredFile('local', $path, hash_file('sha256', $file->getRealPath()));
    }

    public function readStream(string $ref)
    {
        $stream = Storage::disk($this->disk)->readStream($ref);
        if (! $stream) {
            throw new RuntimeException('The stored file is missing.');
        }

        return $stream;
    }
}
