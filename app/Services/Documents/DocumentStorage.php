<?php

namespace App\Services\Documents;

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\Requirement;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * New uploads go to the configured store; existing files are read from the
 * store they were saved in, so switching to the DMS doesn't strand old files.
 */
class DocumentStorage
{
    /** $previous: the version being replaced; a replacement in the same store becomes a new version of it. */
    public function store(UploadedFile $file, Application $application, Requirement $requirement, ?ApplicationDocument $previous = null): StoredFile
    {
        $driver = config('admission.documents.driver');
        $previousRef = $previous?->storage_driver === $driver ? $previous->storage_ref : null;

        return $this->driver($driver)->put($file, $application, $requirement, $previousRef);
    }

    /** @return resource */
    public function readStream(ApplicationDocument $document)
    {
        return $this->driver($document->storage_driver)->readStream($document->storage_ref);
    }

    public function driver(string $name): DocumentStore
    {
        return match ($name) {
            'local' => new LocalDocumentStore(config('admission.documents.local_disk')),
            'dms' => new DmsDocumentStore(
                (string) config('admission.dms.base_url'),
                (string) config('admission.dms.api_key'),
                (int) config('admission.dms.timeout'),
            ),
            default => throw new InvalidArgumentException("Unknown document store [{$name}]."),
        };
    }
}
