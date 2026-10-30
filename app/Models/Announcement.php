<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['title', 'body', 'event_date', 'published_at', 'created_by'])]
class Announcement extends Model
{
    protected function casts(): array
    {
        return ['event_date' => 'date', 'published_at' => 'datetime'];
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id, 'title' => $this->title, 'body' => $this->body,
            'event_date' => $this->event_date?->toDateString(),
            'published' => $this->published_at !== null,
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
}
