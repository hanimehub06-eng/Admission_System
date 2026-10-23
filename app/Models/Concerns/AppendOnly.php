<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * History and audit rows can be inserted and read, never changed (NFR-03).
 * Database triggers enforce the same rule below the application.
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(fn () => throw new LogicException(static::class.' is append-only.'));
        static::deleting(fn () => throw new LogicException(static::class.' is append-only.'));
    }
}
