<?php

namespace App\Events;

use App\Models\Application;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Admission is complete (released pass + medical clearance). This is the
 * point where the applicant goes on the "Admitted list" for Enrollment.
 */
class ApplicantAdmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(public Application $application) {}
}
