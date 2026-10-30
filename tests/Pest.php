<?php

use Tests\Concerns\BuildsApplications;
use Tests\TestCase;

// Pest test files (closures) get the app TestCase and the application builders;
// class-based PHPUnit tests in the same folders keep running unchanged.
pest()->extend(TestCase::class)->use(BuildsApplications::class)->in('Feature', 'Unit');
