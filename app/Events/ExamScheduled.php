<?php

namespace App\Events;

use App\Models\Application;
use App\Models\ExamSession;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ExamScheduled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Application $application,
        public ExamSession $session,
    ) {}
}
