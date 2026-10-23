<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'application_id', 'requirement_id', 'version', 'is_current', 'status', 'storage_driver', 'storage_ref',
    'original_name', 'mime', 'size', 'checksum', 'scan_status', 'remark', 'uploaded_by', 'reviewed_by', 'reviewed_at',
    'photocopy_received_at', 'photocopy_received_by', 'falsification_flag', 'falsification_note', 'falsification_flagged_by',
])]
#[Hidden(['storage_ref', 'storage_driver', 'checksum'])]
class ApplicationDocument extends Model
{
    use HasFactory;

    public const PENDING = 'pending';

    public const VERIFIED = 'verified';

    public const RESUBMIT = 'resubmit';

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'size' => 'integer',
            'version' => 'integer',
            'reviewed_at' => 'datetime',
            'photocopy_received_at' => 'datetime',
            'falsification_flag' => 'boolean',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(Requirement::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
