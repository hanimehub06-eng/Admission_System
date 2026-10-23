<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\Role;
use App\Models\College;
use App\Models\Program;
use App\Models\User;
use App\Notifications\AdmissionNotice;
use App\Notifications\TwoFactorCode;
use App\Services\ApplicationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsApplications;
use Tests\TestCase;

/** FR-09 (offer, slot acceptance, forfeit) and FR-17 (interview). */
class OfferAndInterviewTest extends TestCase
{
    use BuildsApplications;

    public function test_release_issues_an_offer_with_a_deadline(): void
    {
        $user = $this->released();
        $application = $this->app_($user);

        $this->assertSame(ApplicationStatus::Offered, $application->status);
        $this->assertSame('BSIT', $application->offeredProgram->code);
        $this->assertTrue($application->acceptance_deadline->isFuture());

        $deadline = $application->acceptance_deadline->setTimezone('Asia/Manila')->toDateString();
        $this->asUser($user)->getJson('/api/applicant/portal')
            ->assertJsonPath('data.phase', 'result')
            ->assertJsonPath('data.accepted', false)
            ->assertJsonPath('data.offer.deadline', $deadline)
            ->assertJsonFragment(['title' => 'Offer acceptance deadline', 'date' => $deadline]);

        // The letter is a PDF the applicant can download
        $this->asUser($user)->get('/api/applicant/acceptance-letter')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_failed_result_is_final_and_gets_no_offer(): void
    {
        $user = $this->released('failed');
        $this->assertSame(ApplicationStatus::Failed, $this->app_($user)->status);
        $this->asUser($user)->postJson('/api/applicant/offer/accept')->assertStatus(422);
        $this->asUser($user)->get('/api/applicant/acceptance-letter')->assertStatus(422);
    }

    public function test_accepting_takes_the_slot_and_opens_medical(): void
    {
        $user = $this->released();
        $this->asUser($user)->postJson('/api/applicant/offer/accept')
            ->assertOk()->assertJsonPath('data.accepted', true)->assertJsonPath('data.status', 'for_medical');
        $this->asUser($user)->postJson('/api/applicant/offer/accept')->assertOk(); // double click

        $history = $this->app_($user)->statusHistory->pluck('to_status')->map->value->all();
        $this->assertSame(['offered', 'slot_accepted', 'for_medical'], array_slice($history, -3));
        $this->assertSame(1, Program::where('code', 'BSIT')->first()->filledSlots());
        $this->uploadDoc($user, 'medCert', 'medical')->assertCreated();
    }

    public function test_unaccepted_offer_is_forfeited_after_the_deadline(): void
    {
        Notification::fake();
        $user = $this->released();

        $this->travel(6)->days();
        Artisan::call('admission:deadlines', ['--only' => 'offer-reminders']);
        Notification::assertSentTo($user, AdmissionNotice::class, fn ($n) => $n->kind === 'offer_reminder');

        $this->travel(3)->days();
        Artisan::call('admission:deadlines', ['--only' => 'forfeit']);
        $this->assertSame(ApplicationStatus::Forfeited, $this->app_($user)->status);
        Notification::assertSentTo($user, AdmissionNotice::class, fn ($n) => $n->kind === 'offer_forfeited');

        $this->asUser($user)->postJson('/api/applicant/offer/accept')
            ->assertStatus(422)->assertJsonPath('message', 'The acceptance deadline passed and this offer was forfeited. Contact the admission office.');
        $this->assertSame(0, Program::where('code', 'BSIT')->first()->filledSlots());
    }

    /** NFR-04: with acceptance 2FA on, accepting a slot needs the emailed code */
    public function test_slot_acceptance_with_email_code(): void
    {
        config(['admission.applicant_acceptance_2fa' => true]);
        Notification::fake();
        $user = $this->released();

        $challenge = $this->asUser($user)->postJson('/api/applicant/offer/accept')
            ->assertStatus(202)->assertJsonPath('data.two_factor_required', true)->json('data.challenge');
        $code = null;
        Notification::assertSentTo($user, TwoFactorCode::class, function ($n) use (&$code) {
            $code = $n->code;

            return true;
        });

        $this->asUser($user)->postJson('/api/applicant/offer/accept', ['challenge' => $challenge, 'code' => '000000'])->assertStatus(422);
        $this->assertSame(ApplicationStatus::Offered, $this->app_($user)->status);
        $this->asUser($user)->postJson('/api/applicant/offer/accept', ['challenge' => $challenge, 'code' => $code])->assertOk();
        $this->assertSame(ApplicationStatus::ForMedical, $this->app_($user)->status);
    }

    public function test_interview_flow_with_dean_scoping(): void
    {
        $dean = $this->dean('CON');
        $otherDean = $this->dean('CCS');
        $user = $this->interviewee();
        $number = $this->number($user);
        $this->assertSame(ApplicationStatus::ForInterview, $this->app_($user)->status);
        $this->asUser($user)->postJson('/api/applicant/offer/accept')->assertStatus(422);

        // Admission personnel schedule and assign
        $schedule = $this->asUser($this->officer())->postJson('/api/admin/interview-schedules', [
            'program' => 'BSN', 'date' => now()->addDays(3)->toDateString(), 'time' => '9:00 AM', 'venue' => 'Nursing Bldg 105', 'capacity' => 1,
        ])->assertCreated()->json('data.id');
        $this->asUser($this->officer())->postJson('/api/admin/interview-schedules', ['program' => 'BSIT', 'date' => now()->addDay()->toDateString(), 'venue' => 'X', 'capacity' => 5])
            ->assertStatus(422);
        $this->asUser($this->officer())->postJson("/api/admin/applicants/{$number}/interview", ['scheduleId' => $schedule])->assertOk();

        // Capacity 1: a second interviewee doesn't fit
        $second = $this->interviewee('second@example.com');
        $this->asUser($this->officer())->postJson("/api/admin/applicants/{$this->number($second)}/interview", ['scheduleId' => $schedule])
            ->assertStatus(422)->assertJsonPath('message', 'That interview schedule is full. Pick another one.');

        // Dean scoping: the CCS dean sees nothing and can't record; admission personnel can't record
        $this->asUser($otherDean)->getJson('/api/dean/interviews')->assertOk()->assertJsonPath('data.schedules', []);
        $this->asUser($otherDean)->postJson("/api/dean/applicants/{$number}/interview", ['grade' => 90, 'outcome' => 'passed'])->assertForbidden();
        $this->asUser($this->officer())->postJson("/api/dean/applicants/{$number}/interview", ['grade' => 90, 'outcome' => 'passed'])->assertForbidden();
        $this->asUser($dean)->getJson('/api/admin/state')->assertForbidden();

        $this->asUser($dean)->getJson('/api/dean/interviews')->assertOk()->assertJsonPath('data.schedules.0.applicants.0.id', $number);
        $this->asUser($dean)->postJson("/api/dean/applicants/{$number}/interview", ['grade' => 91, 'outcome' => 'passed'])
            ->assertOk()->assertJsonPath('data.status', 'offered');
        $this->asUser($dean)->postJson("/api/dean/applicants/{$number}/interview", ['grade' => 50, 'outcome' => 'failed'])->assertStatus(422);

        // The applicant sees the outcome, never the grade
        $portal = $this->asUser($user)->getJson('/api/applicant/portal')->assertJsonPath('data.interview.outcome', 'passed');
        $this->assertStringNotContainsString('grade', $portal->getContent());
        $this->assertStringNotContainsString('91', json_encode($portal->json('data.interview')));
        // Personnel see the grade
        $this->asUser($this->officer())->getJson('/api/admin/state')->assertJsonFragment(['outcome' => 'passed', 'grade' => 91]);
    }

    public function test_failed_interview_is_final(): void
    {
        $dean = $this->dean('CON');
        $user = $this->interviewee();
        $schedule = $this->asUser($this->officer())->postJson('/api/admin/interview-schedules', [
            'program' => 'BSN', 'date' => now()->addDays(3)->toDateString(), 'venue' => 'Nursing Bldg 105', 'capacity' => 10,
        ])->json('data.id');
        $this->asUser($this->officer())->postJson("/api/admin/applicants/{$this->number($user)}/interview", ['scheduleId' => $schedule])->assertOk();
        $this->asUser($dean)->postJson("/api/dean/applicants/{$this->number($user)}/interview", ['grade' => 55, 'outcome' => 'failed'])->assertOk();

        $this->assertSame(ApplicationStatus::InterviewFailed, $this->app_($user)->status);
        $this->asUser($user)->postJson('/api/applicant/offer/accept')->assertStatus(422);
    }

    /** A pass whose first choice is BS Nursing (requires an interview). */
    private function interviewee(string $email = 'jordan@example.com'): User
    {
        $user = $this->approvedFor('BS Nursing', $email);
        $number = $this->number($user);
        $this->asUser($this->officer())->patchJson("/api/admin/applicants/{$number}/exam", ['permitChecked' => true, 'formChecked' => true, 'attendance' => 'present'])->assertOk();
        $this->asUser($this->officer())->postJson("/api/admin/applicants/{$number}/result", ['score' => 85, 'decision' => 'passed'])->assertOk();
        $this->asUser($this->officer())->postJson('/api/admin/results/release', ['ids' => [$number]])->assertOk();

        return $user;
    }

    private function approvedFor(string $program, string $email): User
    {
        $user = $this->makeApplicant(true, $email);
        app(ApplicationService::class)->saveForm($this->applicationOf($user), ['program1' => $program], $user);
        foreach (app(ApplicationService::class)->requiredDocuments($this->applicationOf($user)) as $r) {
            $this->uploadDoc($user, $r->key)->assertCreated();
        }
        $this->asUser($user)->postJson('/api/applicant/submit')->assertOk();
        $number = $this->number($user);
        $this->asUser($this->officer())->postJson("/api/admin/applicants/{$number}/documents/verify-pending")->assertOk();
        $session = $this->sharedSession ??= $this->examSession();
        $this->asUser($this->officer())->postJson("/api/admin/applicants/{$number}/approve", ['sessionId' => 'S'.$session->id])->assertOk();

        return $user;
    }

    private function dean(string $college): User
    {
        $dean = $this->makeUser(Role::CollegeDean, ['name' => "Dean {$college}"]);
        College::where('code', $college)->update(['dean_user_id' => $dean->id]);

        return $dean;
    }
}
