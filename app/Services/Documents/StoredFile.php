<?php

namespace App\Services\Documents;

final readonly class StoredFile
{
    public function __construct(
        public string $driver,
        public string $ref,
        public string $checksum,
    ) {}
}
