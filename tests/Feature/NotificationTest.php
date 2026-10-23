<?php

namespace Tests\Feature;

use App\Notifications\AdmissionNotice;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsApplications;
use Tests\TestCase;

/** Phase 8: notifications (FR-12, decision T3). */
class NotificationTest extends TestCase
{
    use BuildsApplications;

    private function kinds($user): array
    {
        $kinds = [];
        Notification::assertSentTo($user, AdmissionNotice::class, function (AdmissionNotice $n, array $channels) use (&$kinds) {
            $this->assertSame(['database', 'mail'], $channels);
            $kinds[] = $n->kind;

            return true;
        });

        return $kinds;
    }

    public function test_applicant_is_notified_along_the_way_but_not_before_release(): void
    {
        Notification::fake();
        $user = $this->examined('passed', 90);
        $number = $this->number($user);

        // Submitted and scheduled, but nothing about the recorded result yet
        $this->assertSame(['submitted', 'exam_scheduled'], $this->kinds($user));

        $this->asUser($this->officer())->postJson('/api/admin/results/release', ['ids' => [$number]]);
        $this->assertSame(['submitted', 'exam_scheduled', 'result_released'], $this->kinds($user));

        Notification::assertSentTo($user, AdmissionNotice::class, fn (AdmissionNotice $n) => ! str_contains(strtolower($n->message), 'score') && ! str_contains($n->message, '90'));
    }

    public function test_send_back_notice_carries_the_remark(): void
    {
        Notification::fake();
        $user = $this->submitted();

        $this->asUser($this->officer())->patchJson("/api/admin/applicants/{$this->number($user)}/documents/psa", ['status' => 'resubmit', 'remark' => 'Image is blurry.']);

        Notification::assertSentTo($user, AdmissionNotice::class, fn (AdmissionNotice $n) => $n->kind === 'document_resubmit'
            && $n->message === 'Admissions asked you to upload a new "PSA Birth Certificate": Image is blurry.');
    }

    public function test_in_app_notification_list_and_read(): void
    {
        $user = $this->submitted();

        $res = $this->asUser($user)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread', 1)
            ->assertJsonPath('data.0.kind', 'submitted')
            ->assertJsonPath('data.0.title', 'Application received');

        $this->asUser($user)->postJson('/api/notifications/'.$res->json('data.0.id').'/read')->assertOk()->assertJsonPath('unread', 0);
        // Someone else's notification can't be touched
        $this->asUser($this->officer())->postJson('/api/notifications/'.$res->json('data.0.id').'/read')->assertNotFound();
    }
}
