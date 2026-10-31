<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AdmissionPeriod;
use App\Models\Application;
use App\Models\DmsOutboxEvent;
use App\Models\Strand;
use App\Models\User;
use App\Services\Enrollment\FakeEnrollmentClient;
use Database\Seeders\DemoStaffAccountsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Concerns\BuildsApplications;
use Tests\TestCase;

/**
 * Requirements added or changed by the SDD of 2026-10-24
 * (SDD-PLP-Online-Admission-System.md, sections 4–5).
 */
class SddRequirementsTest extends TestCase
{
    use BuildsApplications;

    /** FR-02: 15-minute lockout after 5 failed sign-ins */
    public function test_account_locks_after_five_failed_sign_ins(): void
    {
        $this->makeApplicant();
        $wrong = ['email' => 'jordan@example.com', 'password' => 'nope-nope', 'portal' => 'applicant'];
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/auth/login', $wrong)->assertJsonPath('errors.email.0', "The email or password doesn't match our records.");
        }
        $this->postJson('/api/auth/login', $wrong)->assertUnprocessable();
        $this->assertNotNull(User::where('email', 'jordan@example.com')->value('locked_until'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.locked']);

        // Even the right password is refused while locked (past the separate 5-per-minute rate limit)
        $this->travel(61)->seconds();
        $this->postJson('/api/auth/login', ['password' => 'password'] + $wrong)
            ->assertUnprocessable()->assertJsonPath('errors.email.0', fn ($m) => str_starts_with($m, 'Too many failed sign-ins'));

        $this->travel(16)->minutes();
        $this->postJson('/api/auth/login', ['password' => 'password'] + $wrong)->assertOk();
        $this->assertSame(0, User::where('email', 'jordan@example.com')->value('failed_logins'));
    }

    /** NFR-04: 15-minute access tokens; refresh works on an expired token once and rotates it */
    public function test_tokens_expire_and_rotate(): void
    {
        $this->assertSame(15, config('jwt.ttl'));
        $user = $this->makeApplicant();
        $token = JWTAuth::fromUser($user);

        $this->travel(20)->minutes();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();

        $fresh = $this->withToken($token)->postJson('/api/auth/refresh')->assertOk()->json('data.token');
        $this->withToken($fresh)->getJson('/api/auth/me')->assertOk();
        // The old token was revoked by the refresh
        $this->withToken($token)->postJson('/api/auth/refresh')->assertUnauthorized();
    }

    /** FR-20, FR-30: only the Admission Head releases; proctors do exam day; read-only users only look */
    public function test_roles(): void
    {
        $number = $this->number($this->examined());

        $this->asUser($this->officer())->postJson('/api/admin/results/release', ['ids' => [$number]])
            ->assertForbidden()->assertJsonPath('message', "Your role can't do this. Ask the Admission Head or the system administrator.");

        $proctor = $this->makeUser(Role::Proctor);
        $session = 'S'.$this->sharedSession->id;
        $this->asUser($proctor)->getJson('/api/admin/state')->assertOk()->assertJsonPath('data.applicants.0.result', fn ($r) => ! isset($r['score']));
        $this->asUser($proctor)->get("/api/admin/exam-sessions/{$session}/roster?format=pdf")->assertOk();
        $this->asUser($proctor)->postJson("/api/admin/applicants/{$number}/result", ['score' => 90, 'decision' => 'passed'])->assertForbidden();

        $readOnly = $this->makeUser(Role::ReadOnly);
        $this->asUser($readOnly)->getJson('/api/admin/state')->assertOk();
        $this->asUser($readOnly)->getJson('/api/reports/status-breakdown')->assertOk();
        $this->asUser($readOnly)->patchJson('/api/admin/programs/BSIT', ['slots' => 10])->assertForbidden();
        $this->asUser($readOnly)->patchJson("/api/admin/applicants/{$number}/exam", ['attendance' => 'absent'])->assertForbidden();

        // Each signs in on the office side
        $this->postJson('/api/auth/login', ['email' => $proctor->email, 'password' => 'password', 'portal' => 'office'])->assertOk();
        $this->postJson('/api/auth/login', ['email' => $readOnly->email, 'password' => 'password', 'portal' => 'office'])->assertOk();

        $this->asUser($this->admissionHead())->postJson('/api/admin/results/release', ['ids' => [$number]])->assertOk();
    }

    /** FR-20: before release the applicant sees "For exam", and the timeline has no result (FR-11) */
    public function test_unreleased_result_is_hidden_and_timeline_is_dated(): void
    {
        $user = $this->examined('failed', 40);
        $this->asUser($user)->getJson('/api/applicant/portal')
            ->assertJsonPath('data.status', 'for_exam')
            ->assertJsonPath('data.statusLabel', 'For exam')
            ->assertJsonMissing(['status' => 'failed'])
            ->assertJsonPath('data.timeline.0.label', 'Draft')
            ->assertJsonPath('data.timeline.1.label', 'Submitted')
            ->assertJsonPath('data.timeline.2.label', 'For exam');

        $this->asUser($this->admissionHead())->postJson('/api/admin/results/release', ['ids' => [$this->number($user)]])->assertOk();
        $this->asUser($user)->getJson('/api/applicant/portal')
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.timeline.3.label', 'Did not pass');
    }

    /** FR-09: rejected with a mandatory remark; the file is fixed and blocks For Exam */
    public function test_document_can_be_rejected(): void
    {
        $user = $this->submitted();
        $number = $this->number($user);

        $this->asUser($this->officer())->patchJson("/api/admin/applicants/{$number}/documents/psa", ['status' => 'rejected'])
            ->assertUnprocessable()->assertJsonPath('message', 'Add a remark so the applicant knows why.');
        $this->asUser($this->officer())->patchJson("/api/admin/applicants/{$number}/documents/psa", ['status' => 'rejected', 'remark' => 'Not a PSA copy.'])
            ->assertOk()->assertJsonPath('data.docs.psa.status', 'rejected');
        $this->asUser($this->officer())->postJson("/api/admin/applicants/{$number}/documents/verify-pending")->assertOk();

        $this->sharedSession = $this->examSession();
        $this->asUser($this->officer())->postJson("/api/admin/applicants/{$number}/approve")
            ->assertUnprocessable()->assertJsonPath('message', 'Verify every required document before approving for the exam.');
        $this->uploadDoc($user, 'psa')->assertUnprocessable();
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $user->id, 'data->kind' => 'document_rejected']);
    }

    /** FR-14: automatic seating, earliest session first, never over capacity */
    public function test_auto_assign_respects_capacity(): void
    {
        $small = $this->examSession(1, '2026-12-05', 'A');
        $next = $this->examSession(5, '2026-12-05', 'B');
        $numbers = [];
        foreach (['a@example.com', 'b@example.com', 'c@example.com'] as $email) {
            $number = $this->number($this->submitted($email));
            $this->asUser($this->officer())->postJson("/api/admin/applicants/{$number}/documents/verify-pending")->assertOk();
            $numbers[] = $number;
        }

        $this->asUser($this->officer())->postJson('/api/admin/exam/auto-assign')->assertOk()->assertJsonPath('assigned', 3);
        $this->assertSame(1, $small->registrations()->count());
        $this->assertSame(2, $next->registrations()->count());
        $this->assertSame($small->id, Application::where('number', $numbers[0])->first()->examRegistration->exam_session_id);
    }

    /** FR-16, FR-17: roster per room and batch, envelope received */
    public function test_roster_and_envelope(): void
    {
        $user = $this->approved();
        $number = $this->number($user);
        $this->asUser($this->officer())->patchJson("/api/admin/applicants/{$number}/exam", ['envelopeReceived' => true])->assertOk();

        $roster = $this->asUser($this->officer())->getJson('/api/admin/exam-sessions/S'.$this->sharedSession->id.'/roster')->assertOk()->json('data');
        $this->assertSame(['#', 'Permit', 'Application no.', 'Name', 'Attendance', 'Envelope', 'Signature'], $roster['columns']);
        $this->assertSame($number, $roster['rows'][0][2]);
        $this->assertSame('Received', $roster['rows'][0][5]);
        $this->assertDatabaseHas('audit_logs', ['description' => 'Received the long brown envelope at the exam venue']);
    }

    /** FR-19: results by CSV; bad lines are reported, good ones recorded; scores stay internal */
    public function test_results_csv_import(): void
    {
        $a = $this->approved('a@example.com');
        $b = $this->approved('b@example.com');
        foreach ([$a, $b] as $u) {
            $this->asUser($this->officer())->patchJson("/api/admin/applicants/{$this->number($u)}/exam", ['permitChecked' => true, 'formChecked' => true, 'attendance' => 'present'])->assertOk();
        }
        $csv = "Application number,Score,Decision\n{$this->number($a)},88,\n{$this->number($b)},abc,passed\nAD-2026-99999,70,passed\n";
        $file = UploadedFile::fake()->createWithContent('results.csv', $csv);

        $res = $this->asUser($this->officer())->post('/api/admin/results/import', ['file' => $file], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(1, $res->json('data.recorded'));
        $this->assertCount(2, $res->json('data.errors'));
        $this->assertSame('passed', $this->app_($a)->status->value);
        $this->asUser($a)->getJson('/api/applicant/portal')->assertJsonMissing(['score' => 88]);
    }

    /** FR-31: under 18 needs a parent's or guardian's consent */
    public function test_minor_needs_guardian_consent(): void
    {
        $user = $this->makeApplicant();
        $app = $this->applicationOf($user);
        $form = $this->completeForm(['guardianConsentName' => null, 'guardianConsent' => null, 'completed' => [1], 'step' => 2]);

        $this->asUser($user)->putJson('/api/applicant/application', $form)
            ->assertUnprocessable()->assertJsonValidationErrors(['guardianConsentName', 'guardianConsent']);

        // 18 or older: no consent needed
        $adult = $form;
        $adult['birthDate'] = now()->subYears(19)->toDateString();
        $this->asUser($user)->putJson('/api/applicant/application', $adult)->assertOk();

        $this->asUser($user)->putJson('/api/applicant/application', ['guardianConsentName' => 'Ana Mendoza', 'guardianConsent' => '1'])->assertOk();
        $this->assertNotNull($app->applicant->fresh()->guardian_consent_at);
    }

    /** FR-06, FR-26: staff maintain strand suggestions and barangays; the form follows */
    public function test_reference_lists(): void
    {
        $this->asUser($this->officer())->putJson('/api/admin/strands', ['name' => 'STEM', 'programs' => ['BSN', 'BSMATH']])->assertOk();
        $this->asUser($this->officer())->putJson('/api/admin/strands', ['name' => 'Maritime', 'programs' => ['NOPE']])->assertUnprocessable();
        $this->assertSame(['BSMATH', 'BSN'], Strand::where('name', 'STEM')->first()->programs()->orderBy('code')->pluck('code')->all());

        $user = $this->makeApplicant(true);
        $this->asUser($user)->getJson('/api/applicant/portal')
            ->assertJsonPath('data.suggestedPrograms', ['BS Mathematics', 'BS Nursing'])
            ->assertJsonPath('data.strandSuggestions.STEM', ['BS Mathematics', 'BS Nursing']);

        $this->asUser($this->officer())->putJson('/api/admin/barangays/2', ['names' => ['Rosario', 'Santolan', 'New Barangay']])->assertOk();
        $this->asUser($user)->putJson('/api/applicant/application', ['district' => '2', 'barangay' => 'New Barangay'])->assertOk()->assertJsonMissingPath('skipped.barangay');
        $this->asUser($user)->putJson('/api/applicant/application', ['district' => '2', 'barangay' => 'Manggahan'])->assertOk()->assertJsonPath('skipped.barangay', 'Pick a barangay in your district.');

        $this->asUser($this->admissionHead())->patchJson('/api/admin/admission-period', ['examStartsOn' => '2026-12-06', 'acceptanceWindowDays' => 10])->assertOk()
            ->assertJsonPath('data.acceptanceWindowDays', 10);
        $this->assertSame(10, AdmissionPeriod::current()->acceptance_window_days);
        $this->assertDatabaseHas('audit_logs', ['action' => 'period.updated']);
        $this->asUser($this->officer())->patchJson('/api/admin/programs/BSIT', ['open' => false])->assertOk()->assertJsonPath('data.open', false);
    }

    /** FR-27, FR-29, FR-11: CSV export, and the admitted applicant given to the Enrollment System as JSON */
    public function test_admitted_applicants_reach_enrollment(): void
    {
        $number = $this->number($this->admitted());

        $csv = $this->asUser($this->officer())->get('/api/reports/admitted-applicants?format=csv')->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
        $this->assertStringContainsString($number, $csv->getContent());

        $sent = array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", Storage::disk('local')->get(FakeEnrollmentClient::FILE))));
        $this->assertCount(1, $sent);
        $applicant = $sent[0]['body'];
        $this->assertSame($number, $applicant['reference_number']);
        $this->assertSame('BSIT', $applicant['program']['code']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $applicant['admitted_at']);   // UTC
        $this->assertArrayNotHasKey('score', $applicant);
        $this->assertSame(DmsOutboxEvent::where('type', 'enrollment.admitted')->value('event_id'), $sent[0]['idempotency_key']);
    }

    /** FR-30: staff accounts linked to EMS are disabled when the employee is inactive or gone */
    public function test_ems_sync_disables_departed_staff(): void
    {
        $super = $this->makeUser(Role::SuperAdmin);
        $this->asUser($super)->postJson('/api/admin/accounts', ['name' => 'Rosa Dizon', 'email' => 'rosa@plpasig.edu.ph', 'role' => 'admission', 'employee_no' => 'E-1'])
            ->assertUnprocessable()->assertJsonValidationErrors('employee_no');
        foreach (['rosa' => '26-00001', 'ben' => '26-00002', 'cora' => '26-00003'] as $name => $no) {
            $this->asUser($super)->postJson('/api/admin/accounts', ['name' => ucfirst($name), 'email' => "{$name}@plpasig.edu.ph", 'role' => 'admission', 'employee_no' => $no])->assertCreated();
        }

        $this->artisan('ems:sync-staff')->expectsOutputToContain('EMS is not connected')->assertSuccessful();

        config(['admission.ems.base_url' => 'https://ems.test/api/v1', 'admission.ems.token' => 't-ems']);
        Http::fake([
            'ems.test/api/v1/employees/26-00001' => Http::response(['data' => ['employee_no' => '26-00001', 'full_name' => 'Rosa Dizon', 'status' => 'active']]),
            'ems.test/api/v1/employees/26-00002' => Http::response(['data' => ['employee_no' => '26-00002', 'full_name' => 'Ben', 'status' => 'inactive']]),
            'ems.test/api/v1/employees/26-00003' => Http::response(['message' => 'Not found.'], 404),
        ]);
        $this->artisan('ems:sync-staff')->expectsOutputToContain('2 disabled, 1 renamed')->assertSuccessful();

        $active = User::whereNotNull('employee_id')->orderBy('employee_id')->pluck('is_active', 'employee_id')->map(fn ($v) => (bool) $v)->all();
        $this->assertSame(['26-00001' => true, '26-00002' => false, '26-00003' => false], $active);
        $this->assertSame('Rosa Dizon', User::where('employee_id', '26-00001')->value('name'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.disabled', 'actor_name' => 'API · ems']);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer t-ems'));
    }

    /** An EMS outage never disables anyone */
    public function test_ems_outage_disables_no_one(): void
    {
        $this->asUser($this->makeUser(Role::SuperAdmin))->postJson('/api/admin/accounts', ['name' => 'Rosa', 'email' => 'rosa@plpasig.edu.ph', 'role' => 'admission', 'employee_no' => '26-00001'])->assertCreated();
        config(['admission.ems.base_url' => 'https://ems.test/api/v1']);
        Http::fake(['ems.test/*' => Http::response(['message' => 'Down'], 503)]);
        Sleep::fake();

        $this->artisan('ems:sync-staff')->assertFailed();
        $this->assertTrue((bool) User::where('employee_id', '26-00001')->value('is_active'));
    }

    /** NFR-08: files of applicants not admitted go 12 months after the period closes */
    public function test_retention(): void
    {
        $failed = $this->released('failed', 'failed@example.com');
        $doc = $this->applicationOf($failed)->documents()->first();

        AdmissionPeriod::current()->update(['is_active' => false]);
        $this->travel(18)->months();
        Artisan::call('app:retention');
        $this->assertStringContainsString('Files due: ', Artisan::output());
        $this->assertNull($doc->fresh()->purged_at);   // report only

        Artisan::call('app:retention', ['--apply' => true]);
        $this->assertNotNull($doc->fresh()->purged_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'retention.applied']);
    }

    /** Demo accounts until the Employment System is connected (project owner, 2026-10-24) */
    public function test_demo_staff_accounts(): void
    {
        $this->seed(DemoStaffAccountsSeeder::class);
        $this->postJson('/api/auth/login', ['email' => 'abella_gabrielrey@plpasig.edu.ph', 'password' => 'admin123', 'portal' => 'office'])
            ->assertOk()->assertJsonPath('data.user.role', 'admission_head');
        $this->postJson('/api/auth/login', ['email' => 'ignacio_keane@plpasig.edu.ph', 'password' => 'clinic123', 'portal' => 'office'])
            ->assertOk()->assertJsonPath('data.user.role', 'clinic');
        foreach (['personnel' => 'personnel', 'proctor' => 'proctor', 'examoffice' => 'exam_office'] as $name => $task) {
            $this->postJson('/api/auth/login', ['email' => "{$name}@plpasig.edu.ph", 'password' => 'password123', 'portal' => 'office'])
                ->assertOk()->assertJsonPath('data.user.role', 'admission');
            $this->assertSame($task, User::where('email', "{$name}@plpasig.edu.ph")->value('task'));
        }
        // Running it again is harmless
        $this->seed(DemoStaffAccountsSeeder::class);
        $this->assertSame(1, User::where('email', 'abella_gabrielrey@plpasig.edu.ph')->count());
    }

    /** NFR-06: CAPTCHA on registration once a provider is configured (off by default) */
    public function test_captcha_on_registration(): void
    {
        $body = ['first_name' => 'Ana', 'last_name' => 'Cruz', 'email' => 'ana@example.com', 'phone' => '+63 917 123 4567',
            'password' => 'password123', 'password_confirmation' => 'password123', 'terms' => true];
        config(['admission.applicant_email_2fa' => false]);
        $this->postJson('/api/auth/register', $body)->assertCreated();   // off: not needed

        config(['admission.captcha' => ['driver' => 'turnstile', 'site_key' => 'site', 'secret' => 'secret']]);
        \Illuminate\Support\Facades\Http::fake(['challenges.cloudflare.com/*' => fn ($r) => \Illuminate\Support\Facades\Http::response(['success' => $r['response'] === 'good'])]);
        $body['email'] = 'ben@example.com';
        $this->postJson('/api/auth/register', $body)->assertJsonValidationErrors('captcha');
        $this->postJson('/api/auth/register', ['captcha' => 'bad'] + $body)->assertJsonValidationErrors('captcha');
        $this->postJson('/api/auth/register', ['captcha' => 'good'] + $body)->assertCreated();
        $this->get('/')->assertSee('cf-turnstile', false);
    }

    /** NFR-06: reCAPTCHA also guards the applicant's sign-in and forgot password; staff sign-in is not affected */
    public function test_recaptcha_on_sign_in_and_forgot_password(): void
    {
        $this->makeApplicant();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        config(['admission.applicant_email_2fa' => false, 'admission.captcha' => ['driver' => 'recaptcha', 'site_key' => 'site', 'secret' => 'secret']]);
        \Illuminate\Support\Facades\Http::fake(['www.google.com/recaptcha/*' => fn ($r) => \Illuminate\Support\Facades\Http::response(['success' => $r['response'] === 'good'])]);
        $login = ['email' => 'jordan@example.com', 'password' => 'password', 'portal' => 'applicant'];

        $this->postJson('/api/auth/login', $login)->assertJsonValidationErrors('captcha');
        $this->postJson('/api/auth/login', ['captcha' => 'bad'] + $login)->assertJsonValidationErrors('captcha');
        $this->postJson('/api/auth/login', ['captcha' => 'good'] + $login)->assertOk();
        $this->postJson('/api/auth/login', ['portal' => 'office'] + $login)->assertJsonMissingValidationErrors('captcha');

        $this->postJson('/api/auth/forgot-password', ['email' => 'jordan@example.com'])->assertJsonValidationErrors('captcha');
        $this->postJson('/api/auth/forgot-password', ['email' => 'jordan@example.com', 'captcha' => 'good'])->assertOk();

        $this->get('/')->assertSee('g-recaptcha', false)->assertSee('google.com/recaptcha/api.js', false);
    }

    /** FR-13: SMS for the exam schedule, only when an SMS provider is set */
    public function test_sms_is_optional(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        config(['admission.sms.driver' => 'log']);
        $this->approved();
        \Illuminate\Support\Facades\Log::shouldHaveReceived('info')->withArgs(fn ($m) => str_starts_with($m, 'SMS to +639171234567: PLP Admissions: Your entrance exam is on'));
    }
}
