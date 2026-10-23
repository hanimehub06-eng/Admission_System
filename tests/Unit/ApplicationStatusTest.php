<?php

namespace Tests\Unit;

use App\Enums\ApplicationStatus as S;
use PHPUnit\Framework\TestCase;

/** FR-05: allowed status transitions. */
class ApplicationStatusTest extends TestCase
{
    public function test_allowed_path(): void
    {
        $this->assertTrue(S::Draft->canMoveTo(S::Submitted));
        $this->assertTrue(S::Submitted->canMoveTo(S::Resubmit));
        $this->assertTrue(S::Resubmit->canMoveTo(S::Submitted));
        $this->assertTrue(S::Submitted->canMoveTo(S::ForExam));
        $this->assertTrue(S::ForExam->canMoveTo(S::Passed));
        $this->assertTrue(S::ForExam->canMoveTo(S::Failed));
        // A result can be corrected before release
        $this->assertTrue(S::Passed->canMoveTo(S::Failed));
    }

    public function test_skipping_steps_is_not_allowed(): void
    {
        $this->assertFalse(S::Draft->canMoveTo(S::ForExam));
        $this->assertFalse(S::Draft->canMoveTo(S::Passed));
        $this->assertFalse(S::Submitted->canMoveTo(S::Passed));
        $this->assertFalse(S::Passed->canMoveTo(S::Draft));
    }

    public function test_only_drafts_are_unlocked(): void
    {
        $this->assertFalse(S::Draft->isLocked());
        foreach ([S::Submitted, S::Resubmit, S::ForExam, S::Passed, S::Failed] as $status) {
            $this->assertTrue($status->isLocked());
        }
    }
}
