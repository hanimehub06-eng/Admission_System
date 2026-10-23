<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One record change waiting for, or delivered to, the Document Management
 * System (INT-01). event_id is the Idempotency-Key, so a retry never makes
 * a duplicate in the DMS.
 */
#[Fillable(['event_id', 'type', 'applicant_ref', 'payload', 'status', 'attempts', 'last_error', 'sent_at'])]
class DmsOutboxEvent extends Model
{
    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    protected $table = 'dms_outbox';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
        ];
    }
}
