<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ExamResult;
use App\Models\User;
use App\Services\ApplicationService;
use App\Services\Security\MalwareScanner;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Phase 4: document upload, review and resubmission (FR-03, FR-04), medical (FR-13). */
class DocumentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function pdf(string $name = 'file.pdf', int $kb = 200): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kb, 'application/pdf');
    }

    private function upload(User $user, string $key, ?UploadedFile $file = null, string $stage = 'documents')
    {
        return $this->asUser($user)->post("/api/applicant/{$stage}/{$key}", ['file' => $file ?? ($key === 'photo' ? $this->fakePhoto() : $this->pdf())], ['Accept' => 'application/json']);
    }

    /** A filled, submitted application with every required document uploaded. */
    private function submittedApplicant(): User
    {
        $user = $this->makeApplicant(true);
        foreach (app(ApplicationService::class)->requiredDocuments($this->applicationOf($user)) as $r) {
            $this->upload($user, $r->key)->assertCreated();
        }
        $this->asUser($user)->postJson('/api/applicant/submit')->assertOk();

        return $user;
    }

    /** FR-03 */
    public function test_applicant_uploads_to_private_storage(): void
    {
        $user = $this->makeApplicant(true);

        $this->upload($user, 'psa', $this->pdf('psa_birth_certificate.pdf'))
            ->assertCreated()
            ->assertJsonPath('data.documents.psa.name', 'psa_birth_certificate.pdf')
            ->assertJsonPath('data.documents.psa.status', 'pending')
            ->assertJsonMissingPath('data.documents.psa.storage_ref');

        $doc = ApplicationDocument::first();
        Storage::disk('local')->assertExists($doc->storage_ref);
        $this->assertStringStartsWith('documents/'.$this->applicationOf($user)->number.'/', $doc->storage_ref);
        $this->assertStringNotContainsString('psa_birth', $doc->storage_ref); // random name
    }

    /** Decision T8: type and 5 MB limit */
    public function test_upload_rules(): void
    {
        $user = $this->makeApplicant(true);

        $this->upload($user, 'psa', $this->pdf('big.pdf', 5121))->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'This file is over 5 MB. Compress it or choose a smaller file.');
        $this->upload($user, 'photo', $this->pdf())->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'Choose a JPG or PNG photo.');
        $this->upload($user, 'psa', UploadedFile::fake()->create('script.exe', 10, 'application/x-msdownload'))->assertUnprocessable();
        $this->upload($user, 'nope')->assertUnprocessable();
        // Guardianship affidavit applies only to applicants with a guardian
        $this->upload($user, 'affidavit')->assertUnprocessable();
        // Medical opens only after a released pass
        $this->upload($user, 'medCert', null, 'medical')->assertUnprocessable();
    }

    public function test_replace_keeps_versions_and_remove_in_draft(): void
    {
        $user = $this->makeApplicant(true);
        $this->upload($user, 'psa')->assertCreated();
        $this->upload($user, 'psa', $this->pdf('second.pdf'))->assertCreated();

        $this->assertSame(2, ApplicationDocument::count());
        $this->assertSame(2, ApplicationDocument::where('is_current', true)->first()->version);

        $this->asUser($user)->deleteJson('/api/applicant/documents/psa')->assertOk()->assertJsonMissingPath('data.documents.psa');
    }

    public function test_documents_lock_after_submission(): void
    {
        $user = $this->submittedApplicant();

        $this->upload($user, 'psa')->assertUnprocessable();
        $this->asUser($user)->deleteJson('/api/applicant/documents/psa')->assertUnprocessable();
    }

    /** FR-04: full review → resubmit → review loop */
    public function test_review_and_resubmission_loop(): void
    {
        $user = $this->submittedApplicant();
        $application = $this->applicationOf($user);
        $staff = $this->makeUser(Role::Admission, ['name' => 'Maria Santos']);
        $url = "/api/admin/applicants/{$application->number}/documents";

        // Remark is required to send a file back
        $this->asUser($staff)->patchJson("{$url}/psa", ['status' => 'resubmit'])->assertUnprocessable();

        $this->asUser($staff)->patchJson("{$url}/psa", ['status' => 'resubmit', 'remark' => 'Image is blurry.'])
            ->assertOk()
            ->assertJsonPath('data.docs.psa.status', 'resubmit')
            ->assertJsonPath('data.docs.psa.remark', 'Image is blurry.')
            ->assertJsonPath('data.docs.psa.reviewedBy', 'Maria Santos');
        $this->assertSame(ApplicationStatus::Resubmit, $application->fresh()->status);

        $this->asUser($staff)->postJson("{$url}/verify-pending")->assertOk()->assertJsonPath('verified', 6);   // the 7 document cards minus PSA (sent back)

        // The applicant may replace only the file that was sent back
        $this->asUser($user)->getJson('/api/applicant/portal')->assertJsonPath('data.phase', 'draft');
        $this->upload($user, 'photo')->assertUnprocessable();
        $this->upload($user, 'psa', $this->pdf('clear.pdf'))->assertCreated();
        $this->asUser($user)->postJson('/api/applicant/submit')->assertOk()->assertJsonPath('data.phase', 'review');
        $this->assertSame(ApplicationStatus::Submitted, $application->fresh()->status);

        $this->asUser($staff)->patchJson("{$url}/psa", ['status' => 'verified'])->assertOk()->assertJsonPath('data.docs.psa.status', 'verified');
        $this->assertDatabaseHas('audit_logs', ['description' => 'Marked "PSA Birth Certificate" as requires resubmission: Image is blurry.']);
        $this->assertDatabaseHas('audit_logs', ['description' => 'Resubmitted the requested documents']);
    }

    /** NFR-01: medical files are confidential to the clinic */
    public function test_reviewer_roles_and_file_access(): void
    {
        $user = $this->submittedApplicant();
        $number = $this->applicationOf($user)->number;
        $clinic = $this->makeUser(Role::Clinic);
        $staff = $this->makeUser(Role::Admission);

        $this->asUser($clinic)->patchJson("/api/admin/applicants/{$number}/documents/psa", ['status' => 'verified'])->assertForbidden();
        $this->asUser($clinic)->get("/api/admin/applicants/{$number}/documents/psa/file")->assertForbidden();
        $this->asUser($clinic)->getJson('/api/admin/state')->assertForbidden();
        $this->asUser($staff)->getJson('/api/clinic/state')->assertForbidden();
        $this->asUser($staff)->get("/api/admin/applicants/{$number}/documents/psa/file")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->asUser($user)->get('/api/applicant/documents/psa/file')->assertOk();
        $this->asUser($user)->getJson('/api/admin/state')->assertForbidden();
    }

    /** Public university: no fee and no payment step anywhere. Passers just accept the offer. */
    public function test_no_payment_step(): void
    {
        $user = $this->submittedApplicant();
        $application = $this->applicationOf($user);
        $this->acceptedSlot($application);

        $portal = $this->asUser($user)->getJson('/api/applicant/portal')->assertOk();
        $this->assertNotContains('payment', array_column($portal->json('data.requirements'), 'key'));
        $this->assertArrayNotHasKey('payment', $portal->json('data'));
        $this->assertNotContains('Reservation fee deadline', array_column($portal->json('data.keyDates'), 'title'));
        $this->asUser($user)->post('/api/applicant/documents/payment', ['file' => $this->pdf()], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    /** FR-10: medical requirements after slot acceptance; clinic only; all verified → Admitted */
    public function test_medical_flow(): void
    {
        $user = $this->submittedApplicant();
        $application = $this->applicationOf($user);
        $this->acceptedSlot($application);
        $clinic = $this->makeUser(Role::Clinic, ['name' => 'Dr. L. Bautista']);
        $admission = $this->makeUser(Role::Admission);

        foreach (['medCert', 'xray', 'labs'] as $key) {
            $this->upload($user, $key, null, 'medical')->assertCreated();
        }
        $this->asUser($user)->postJson('/api/applicant/medical/submit')->assertOk()->assertJsonPath('data.medical.status', 'submitted');

        $url = "/api/clinic/applicants/{$application->number}/medical";
        $this->asUser($admission)->patchJson("{$url}/xray", ['status' => 'verified'])->assertForbidden();
        $this->asUser($admission)->postJson("{$url}/approve")->assertForbidden();
        $this->asUser($clinic)->get("{$url}/xray/file")->assertOk();
        $this->asUser($admission)->get("{$url}/xray/file")->assertForbidden();
        $this->asUser($admission)->get("/api/admin/applicants/{$application->number}/documents/xray/file")->assertForbidden();

        // The clinic's state: medical files and a name, nothing else from the record
        $state = $this->asUser($clinic)->getJson('/api/clinic/state')->assertOk();
        $this->assertSame([$application->number], array_column($state->json('data.applicants'), 'id'));
        $this->assertSame('pending', $state->json('data.applicants.0.medical.files.xray.status'));
        $this->assertNotNull($state->json('data.applicants.0.medical.submittedAt'));
        $this->assertSame([], $state->json('data.applicants.0.docs'));
        $this->assertNull($state->json('data.applicants.0.exam'));
        $this->assertArrayNotHasKey('email', $state->json('data.applicants.0'));

        $this->asUser($clinic)->patchJson("{$url}/xray", ['status' => 'resubmit', 'remark' => 'Result is older than 3 months.'])
            ->assertOk()->assertJsonPath('data.medical.files.xray.status', 'resubmit');
        // The sent-back file disappears from the portal so the card asks for an upload again
        $this->asUser($user)->getJson('/api/applicant/portal')
            ->assertJsonPath('data.medical.status', 'none')
            ->assertJsonMissingPath('data.medical.files.xray');

        // Can't approve while a file is sent back, or before the replacement is sent
        $this->asUser($clinic)->postJson("{$url}/approve")->assertUnprocessable();
        $this->upload($user, 'xray', null, 'medical')->assertCreated();
        $this->asUser($clinic)->postJson("{$url}/approve")->assertUnprocessable();
        $this->asUser($user)->postJson('/api/applicant/medical/submit')->assertOk();
        $this->asUser($clinic)->postJson("{$url}/approve")->assertOk()->assertJsonPath('verified', 3)
            ->assertJsonPath('data.medical.files.xray.status', 'verified');
        $this->asUser($clinic)->postJson("{$url}/approve")->assertUnprocessable();

        $this->asUser($user)->getJson('/api/applicant/portal')
            ->assertJsonPath('data.medical.status', 'cleared')
            ->assertJsonPath('data.status', 'admitted');
        $this->assertDatabaseHas('audit_logs', ['description' => 'Medically cleared · admission complete', 'actor_name' => 'Dr. L. Bautista']);
        $this->assertNotNull($application->fresh()->admitted_at);
        // Every opening of a medical file is audited (NFR-05)
        $this->assertDatabaseHas('audit_logs', ['action' => 'medical.file_opened']);
        $this->assertDatabaseHas('audit_logs', ['description' => 'Approved the medical requirements and sent the approval to the admission office', 'actor_name' => 'Dr. L. Bautista']);

        // The office sees every file verified (= "Approved"), read-only
        $office = $this->asUser($admission)->getJson('/api/admin/state')->assertOk();
        $this->assertSame('verified', $office->json('data.applicants.0.medical.files.xray.status'));
    }

    /** FR-10: a file flagged as possibly falsified holds clearance until the flag is cleared */
    public function test_falsification_flag_holds_admission(): void
    {
        $user = $this->submittedApplicant();
        $application = $this->applicationOf($user);
        $this->acceptedSlot($application);
        $clinic = $this->makeUser(Role::Clinic);
        foreach (['medCert', 'xray', 'labs'] as $key) {
            $this->upload($user, $key, null, 'medical')->assertCreated();
        }
        $this->asUser($user)->postJson('/api/applicant/medical/submit')->assertOk();
        $url = "/api/clinic/applicants/{$application->number}/medical";

        $this->asUser($clinic)->patchJson("{$url}/medCert/flag", ['flagged' => true])->assertStatus(422); // note required
        $this->asUser($this->makeUser(Role::Admission))->patchJson("{$url}/medCert/flag", ['flagged' => true, 'note' => 'x'])->assertForbidden();
        $this->asUser($clinic)->patchJson("{$url}/medCert/flag", ['flagged' => true, 'note' => 'Signature does not match the clinic on file.'])
            ->assertOk()->assertJsonPath('data.medical.files.medCert.flagged', true);
        $this->asUser($clinic)->postJson("{$url}/approve")->assertOk();
        $this->assertSame(ApplicationStatus::ForMedical, $application->fresh()->status);

        $this->asUser($clinic)->patchJson("{$url}/medCert/flag", ['flagged' => false])->assertOk();
        $this->assertSame(ApplicationStatus::Admitted, $application->fresh()->status);
    }

    /** §9.1: photocopies handed in are ticked off by the right office */
    public function test_photocopy_received(): void
    {
        $user = $this->submittedApplicant();
        $number = $this->applicationOf($user)->number;
        $staff = $this->makeUser(Role::Admission, ['name' => 'Maria Santos']);

        $this->asUser($this->makeUser(Role::Clinic))->patchJson("/api/admin/applicants/{$number}/documents/psa/photocopy", ['received' => true])->assertForbidden();
        $this->asUser($staff)->patchJson("/api/admin/applicants/{$number}/documents/psa/photocopy", ['received' => true])
            ->assertOk()->assertJsonStructure(['data' => ['docs' => ['psa' => ['photocopyAt']]]]);
        $this->assertDatabaseHas('audit_logs', ['description' => 'Received the photocopy of "PSA Birth Certificate"']);
        $this->assertNotNull(ApplicationDocument::whereNotNull('photocopy_received_at')->sole()->photocopy_received_by);
    }

    /** Uploads are virus-scanned; an infected file is refused and nothing is stored */
    public function test_infected_upload_is_refused(): void
    {
        $this->app->instance(MalwareScanner::class, new class implements MalwareScanner
        {
            public function scan(string $path): string
            {
                throw new BusinessRuleException('This file was rejected by the virus scan. Upload a different copy.');
            }
        });
        $user = $this->makeApplicant(true);

        $this->upload($user, 'psa')->assertStatus(422)->assertJsonPath('message', 'This file was rejected by the virus scan. Upload a different copy.');
        $this->assertSame(0, ApplicationDocument::count());
    }

    /** §9.1/§9.3: files go straight to the DMS; a replacement is a new version of the same document */
    public function test_dms_store(): void
    {
        config(['admission.documents.driver' => 'dms', 'admission.dms.base_url' => 'https://dms.test/api', 'admission.dms.api_key' => 'k-123']);
        Http::fake([
            'dms.test/api/documents' => Http::response(['id' => 'DMS-778'], 201),
            'dms.test/api/documents/DMS-778/versions' => Http::response(['id' => 'DMS-778'], 201),
            'dms.test/api/documents/DMS-778/download-url' => Http::response(['url' => 'https://files.dms.test/tmp/abc'], 200),
            'files.dms.test/*' => Http::response('%PDF-1.4 test', 200),
        ]);
        $user = $this->makeApplicant(true);

        $this->upload($user, 'psa')->assertCreated();
        $doc = ApplicationDocument::first();
        $this->assertSame(['dms', 'DMS-778'], [$doc->storage_driver, $doc->storage_ref]);
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('X-API-Key', 'k-123') && $r->hasHeader('Idempotency-Key'));

        // Replaced in the draft: sent as a new version
        $this->upload($user, 'psa', UploadedFile::fake()->create('psa2.pdf', 150, 'application/pdf'))->assertCreated();
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/documents/DMS-778/versions'));

        // Opened through the OAS: the 5-minute link never reaches the browser
        $this->asUser($user)->get('/api/applicant/documents/psa/file')->assertOk();
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/download-url'));
    }

    /** Stand-in for Phases 6–9: a released pass whose slot the applicant accepted. */
    private function acceptedSlot(Application $application): void
    {
        $staff = $this->makeUser(Role::Admission);
        $application->forceFill(['status' => ApplicationStatus::ForMedical, 'offered_program_id' => $application->firstChoice()->id, 'offered_at' => now(), 'offer_accepted_at' => now()])->save();
        ExamResult::create(['application_id' => $application->id, 'score' => 80, 'decision' => 'passed', 'recorded_by' => $staff->id, 'recorded_at' => now(), 'is_released' => true, 'released_by' => $staff->id, 'released_at' => now()]);
    }
}
