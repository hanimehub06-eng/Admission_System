<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Applicant;
use App\Models\User;
use App\Notifications\TwoFactorCode;
use App\Notifications\VerifyApplicantEmail;
use App\Services\ApplicationService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\BuildsApplications;
use Tests\TestCase;

/** NFR-04/NFR-05, FR-01, OBJ-05/OBJ-11: what each role can and can't reach or see. */
class SecurityTest extends TestCase
{
    use BuildsApplications;

    /** OBJ-05: no score or grade in any applicant payload, at any stage */
    public function test_no_restricted_values_reach_the_applicant(): void
    {
        $user = $this->admitted();
        $score = $this->app_($user)->result->score;

        foreach (['/api/applicant/portal', '/api/auth/me', '/api/notifications'] as $url) {
            $body = $this->asUser($user)->getJson($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('"score"', $body, $url);
            $this->assertStringNotContainsString('"grade"', $body, $url);
        }
        $this->assertStringNotContainsString((string) $score, json_encode($this->asUser($user)->getJson('/api/applicant/portal')->json('data.result')));
        // The clinic doesn't get scores either
        $this->assertStringNotContainsString('"score"', $this->asUser($this->clinic())->getJson('/api/admin/state')->getContent());
    }

    /** §7.1: personnel can't reach dean or sysadmin functions; the dean can't reach the office panel */
    public function test_role_boundaries(): void
    {
        $dean = $this->makeUser(Role::CollegeDean);
        $sysadmin = $this->makeUser(Role::Sysadmin);
        $applicant = $this->makeApplicant();

        $this->asUser($this->officer())->getJson('/api/dean/interviews')->assertForbidden();
        $this->asUser($dean)->getJson('/api/admin/state')->assertForbidden();
        $this->asUser($dean)->getJson('/api/admin/interviews')->assertForbidden();
        $this->asUser($applicant)->getJson('/api/reports')->assertForbidden();
        $this->asUser($sysadmin)->getJson('/api/admin/state')->assertForbidden();
        $this->postJson('/api/auth/login', ['email' => $sysadmin->email, 'password' => 'password', 'portal' => 'office'])->assertStatus(422);
        // The dean signs in on the office side
        $this->postJson('/api/auth/login', ['email' => $dean->email, 'password' => 'password', 'portal' => 'office'])->assertOk();
    }

    /** NFR-04: with STAFF_2FA_REQUIRED, staff need enrolled 2FA and a token from the 2FA step */
    public function test_staff_two_factor_enforcement(): void
    {
        config(['admission.staff_2fa_required' => true]);
        $staff = $this->makeUser(Role::Admission);

        $this->postJson('/api/auth/login', ['email' => $staff->email, 'password' => 'password', 'portal' => 'office'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'Two-factor sign-in must be set up for this account first. Ask the system administrator.');
        // A token issued without the 2FA step is refused on staff routes
        $this->asUser($staff)->getJson('/api/admin/state')->assertForbidden();

        $this->artisan('user:2fa', ['email' => $staff->email, 'method' => 'email'])->assertSuccessful();
        Notification::fake();
        $challenge = $this->postJson('/api/auth/login', ['email' => $staff->email, 'password' => 'password', 'portal' => 'office'])
            ->assertOk()->json('data.challenge');
        $code = null;
        Notification::assertSentTo($staff, TwoFactorCode::class, function ($n) use (&$code) {
            $code = $n->code;

            return true;
        });
        $token = $this->postJson('/api/auth/2fa/verify', ['challenge' => $challenge, 'code' => $code])->assertOk()->json('data.token');
        $this->flushHeaders()->withHeader('Authorization', "Bearer {$token}")->getJson('/api/admin/state')->assertOk();
    }

    /** NFR-04: an applicant's device is trusted after one email code */
    public function test_applicant_trusted_device(): void
    {
        config(['admission.applicant_email_2fa' => true]);
        Notification::fake();
        $user = $this->makeApplicant();
        $login = fn (array $extra = []) => $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password', 'portal' => 'applicant'] + $extra);

        $challenge = $login()->assertOk()->assertJsonPath('data.two_factor_required', true)->json('data.challenge');
        $code = null;
        Notification::assertSentTo($user, TwoFactorCode::class, function ($n) use (&$code) {
            $code = $n->code;

            return true;
        });
        $device = $this->postJson('/api/auth/2fa/verify', ['challenge' => $challenge, 'code' => $code])->assertOk()->json('data.device_token');

        $login(['device_token' => $device])->assertOk()->assertJsonPath('data.token', fn ($t) => is_string($t));
        $login(['device_token' => 'not-mine'])->assertJsonPath('data.two_factor_required', true);
        // A slot-acceptance challenge can't be used to sign in
        $this->assertDatabaseHas('audit_logs', ['action' => 'applicant.login', 'description' => 'Signed in to the applicant portal (email code)']);
    }

    /** FR-01, NFR-05: privacy notice accepted, email verified before submitting */
    public function test_registration_privacy_and_email_verification(): void
    {
        Notification::fake();
        $this->postJson('/api/auth/register', [
            'first_name' => 'Ana', 'last_name' => 'Reyes', 'email' => 'ana@example.com', 'phone' => '+63 917 123 4567',
            'password' => 'password123', 'password_confirmation' => 'password123', 'terms' => true,
        ])->assertCreated()->assertJsonPath('data.user.email_verified', false);
        $user = User::where('email', 'ana@example.com')->first();
        $this->assertNotNull($user->privacy_accepted_at);

        $url = null;
        Notification::assertSentTo($user, VerifyApplicantEmail::class, function ($n) use ($user, &$url) {
            $url = $n->toMail($user)->actionUrl;

            return true;
        });

        // Can't submit until the email is confirmed
        app(ApplicationService::class)->saveForm($this->applicationOf($user), $this->completeForm(['lrn' => '555555555555']), $user);
        $this->asUser($user)->postJson('/api/applicant/submit')
            ->assertStatus(422)->assertJsonPath('message', 'Confirm your email address first: open the link we sent to ana@example.com.');

        $this->get(str_replace(URL::to('/'), '', $url).'x')->assertForbidden(); // tampered link
        $this->get($url)->assertRedirect('/?verified=1');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());

        $this->asUser($user)->postJson('/api/auth/email/resend')->assertOk()->assertJsonPath('message', 'Your email address is already confirmed.');
    }

    /** FR-01: the same person registering twice is flagged for staff, not blocked */
    public function test_possible_duplicate_is_flagged(): void
    {
        $first = $this->makeApplicant(true);
        $second = $this->makeApplicant(true, 'jordan.m@example.com');

        $this->assertSame($first->applicant->id, Applicant::find($second->applicant->id)->possible_duplicate_of);
        $this->asUser($this->officer())->getJson('/api/admin/state')
            ->assertJsonFragment(['possibleDuplicateOf' => $this->number($first)]);
    }
}
