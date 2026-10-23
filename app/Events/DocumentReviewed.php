<?php

namespace App\Events;

use App\Models\ApplicationDocument;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DocumentReviewed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ApplicationDocument $document,
        public User $reviewer,
    ) {}
}
