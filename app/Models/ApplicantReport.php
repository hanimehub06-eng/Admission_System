<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['application_id', 'exam_session_id', 'reported_by', 'category', 'body'])]
class ApplicantReport extends Model
{
    public const CATEGORIES = ['incident', 'misconduct', 'health', 'other'];

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id, 'category' => $this->category, 'body' => $this->body,
            'reported_by' => $this->reporter?->name,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
