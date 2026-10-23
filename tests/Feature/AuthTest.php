<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\Role;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\TwoFactorCode;
use App\Notifications\VerifyApplicantEmail;
use App\Services\TwoFactorService;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** Phase 2: authentication and access control (NFR-01, FR-01). */
class AuthTest extends TestCase
{
    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Jordan', 'last_name' => 'Mendoza', 'email' => 'jordan@example.com',
            'phone' => '+63 917 123 4567', 'password' => 'secret123', 'password_confirmation' => 'secret123', 'terms' => true,
        ], $overrides);
    }

    private function login(string $email, string $portal, string $password = 'password'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/login', compact('email', 'password', 'portal'));
    }

    /** FR-01 */
    public function test_register_creates_applicant_with_draft_application_and_token(): void
    {
        $res = $this->postJson('/api/auth/register', $this->registerPayload())
            ->assertCreated()
            ->assertJsonPath('data.user.role', 'applicant')
            ->assertJsonStructure(['data' => ['token', 'expires_in']]);

        $user = User::where('email', 'jordan@example.com')->first();
        $application = Application::first();
        $this->assertTrue($user->hasRole('applicant'));
        $this->assertSame('Jordan', $user->applicant->first_name);
        $this->assertSame(ApplicationStatus::Draft, $application->status);
        $this->assertMatchesRegularExpression('/^AD-\d{4}-\d{5}$/', $application->number);
        $this->assertSame(1, $application->statusHistory()->count());

        $this->withToken($res->json('data.token'))->getJson('/api/auth/me')->assertJsonPath('data.email', 'jordan@example.com');
    }

    /** FR-01: one account per email */
    public function test_register_rejects_duplicate_email_and_bad_phone(): void
    {
        $this->postJson('/api/auth/register', $this->registerPayload())->assertCreated();
        $this->postJson('/api/auth/register', $this->registerPayload(['phone' => '12345']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'phone']);
    }

    /** NFR-01: each portal only lets its own roles in */
    public function test_portals_only_accept_their_roles(): void
    {
        $applicant = $this->makeUser(Role::Applicant);
        $admission = $this->makeUser(Role::Admission);
        $clinic = $this->makeUser(Role::Clinic);
        $sysadmin = $this->makeUser(Role::Sysadmin);

        $this->login($applicant->email, 'applicant')->assertOk();
        $this->login($applicant->email, 'office')->assertUnprocessable();
        $this->login($admission->email, 'office')->assertOk();
        $this->login($admission->email, 'clinic')->assertUnprocessable();
        $this->login($clinic->email, 'clinic')->assertOk();
        $this->login($clinic->email, 'office')->assertUnprocessable()->assertJsonPath('errors.email.0', "This account can't sign in here. Clinic accounts sign in at the clinic portal (/nurse).");
        $this->login($admission->email, 'applicant')->assertUnprocessable();
        $this->login($sysadmin->email, 'clinic')->assertUnprocessable();
        $this->login($sysadmin->email, 'office')->assertUnprocessable();
        $this->login($sysadmin->email, 'applicant')->assertUnprocessable();
    }

    public function test_staff_login_is_audited(): void
    {
        $staff = $this->makeUser(Role::Admission, ['name' => 'Maria Santos']);
        $this->login($staff->email, 'office')->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'actor_name' => 'Maria Santos']);
    }

    public function test_wrong_password_and_disabled_account_are_rejected(): void
    {
        $user = $this->makeUser(Role::Applicant);
        $this->login($user->email, 'applicant', 'wrong-password')->assertUnprocessable();

        $user->forceFill(['is_active' => false])->save();
        $this->login($user->email, 'applicant')->assertUnprocessable();
    }

    /** NFR-01: disabling an account cuts off a token that is still valid */
    public function test_disabled_account_loses_access_with_existing_token(): void
    {
        $user = $this->makeUser(Role::Applicant);
        $this->asUser($user)->getJson('/api/auth/me')->assertOk();

        $user->forceFill(['is_active' => false])->save();
        $this->asUser($user)->getJson('/api/auth/me')->assertForbidden();
    }

    public function test_logout_invalidates_the_token(): void
    {
        $user = $this->makeUser(Role::Applicant);
        $token = $this->login($user->email, 'applicant')->json('data.token');

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_guest_cannot_reach_protected_routes(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_login_is_rate_limited(): void
    {
        $user = $this->makeUser(Role::Applicant);
        for ($i = 0; $i < 5; $i++) {
            $this->login($user->email, 'applicant', 'wrong')->assertUnprocessable();
        }
        $this->login($user->email, 'applicant', 'wrong')->assertTooManyRequests();
    }

    /** Decision T4: email code for applicants */
    public function test_applicant_email_two_factor(): void
    {
        Notification::fake();
        config(['admission.applicant_email_2fa' => true]);
        $user = $this->makeUser(Role::Applicant);

        $challenge = $this->login($user->email, 'applicant')
            ->assertOk()
            ->assertJsonPath('data.two_factor_required', true)
            ->assertJsonPath('data.method', 'email')
            ->assertJsonMissingPath('data.token')
            ->json('data.challenge');

        $code = null;
        Notification::assertSentTo($user, TwoFactorCode::class, function (TwoFactorCode $n) use (&$code) {
            $code = $n->code;

            return true;
        });

        $this->postJson('/api/auth/2fa/verify', ['challenge' => $challenge, 'code' => '000000'])->assertUnprocessable();
        $this->postJson('/api/auth/2fa/verify', ['challenge' => $challenge, 'code' => $code])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token']]);
        // A challenge works once
        $this->postJson('/api/auth/2fa/verify', ['challenge' => $challenge, 'code' => $code])->assertUnprocessable();
    }

    /** Applicant 2FA at sign-up: no session until the emailed code; the code confirms the email */
    public function test_register_with_email_code(): void
    {
        Notification::fake();
        config(['admission.applicant_email_2fa' => true]);

        $challenge = $this->postJson('/api/auth/register', $this->registerPayload())
            ->assertCreated()
            ->assertJsonPath('data.two_factor_required', true)
            ->assertJsonMissingPath('data.token')
            ->json('data.challenge');
        $user = User::where('email', 'jordan@example.com')->first();
        $this->assertFalse($user->hasVerifiedEmail());
        Notification::assertNotSentTo($user, VerifyApplicantEmail::class);

        $codes = [];
        Notification::assertSentTo($user, TwoFactorCode::class, function (TwoFactorCode $n) use (&$codes) {
            $codes[] = $n->code;

            return true;
        });

        // Resend: too soon, then a new code that replaces the old one
        $this->postJson('/api/auth/2fa/resend', compact('challenge'))->assertUnprocessable();
        $this->travel(31)->seconds();
        $this->postJson('/api/auth/2fa/resend', compact('challenge'))->assertOk();
        $codes = [];
        Notification::assertSentTo($user, TwoFactorCode::class, function (TwoFactorCode $n) use (&$codes) {
            $codes[] = $n->code;

            return true;
        });
        $this->assertCount(2, $codes);
        [$old, $new] = $codes;
        if ($old !== $new) {
            $this->postJson('/api/auth/2fa/verify', ['challenge' => $challenge, 'code' => $old])->assertUnprocessable();
        }

        $this->postJson('/api/auth/2fa/verify', ['challenge' => $challenge, 'code' => $new])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'applicant')
            ->assertJsonStructure(['data' => ['token', 'device_token']]);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    /** Decision T4: authenticator app for staff */
    public function test_staff_totp_two_factor(): void
    {
        $staff = $this->makeUser(Role::Admission);
        app(TwoFactorService::class)->enableTotp($staff);

        $challenge = $this->login($staff->email, 'office')->assertJsonPath('data.method', 'totp')->json('data.challenge');
        $code = app(Google2FA::class)->getCurrentOtp($staff->fresh()->two_factor_secret);

        $this->postJson('/api/auth/2fa/verify', compact('challenge', 'code'))->assertOk()->assertJsonPath('data.user.role', 'admission');
    }

    /** No account enumeration */
    public function test_forgot_password_answers_the_same_for_unknown_email(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com'])
            ->assertOk()
            ->assertJsonPath('message', 'If an account exists for nobody@example.com, a reset link is on its way.');
    }

    /** D7: staff accounts come from the sysadmin's artisan command, not the API */
    public function test_sysadmin_creates_staff_with_artisan(): void
    {
        $this->artisan('user:create', ['email' => 'new@plpasig.edu.ph', 'name' => 'New Staff', 'role' => 'clinic'])->assertSuccessful();
        $this->artisan('user:create', ['email' => 'kid@example.com', 'name' => 'Kid', 'role' => 'applicant'])->assertFailed();

        $this->assertTrue(User::where('email', 'new@plpasig.edu.ph')->first()->hasRole('clinic'));
        $this->assertSame(1, AuditLog::where('action', 'account.created')->count());
    }
}
