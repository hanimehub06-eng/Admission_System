<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['application_id', 'exam_session_id', 'permit_no', 'qr_token', 'permit_checked', 'form_checked', 'attendance', 'attendance_by', 'attendance_at', 'approved_by', 'reminder_sent_at'])]
class ExamRegistration extends Model
{
    protected function casts(): array
    {
        return [
            'permit_checked' => 'boolean',
            'form_checked' => 'boolean',
            'attendance_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'exam_session_id');
    }
}
