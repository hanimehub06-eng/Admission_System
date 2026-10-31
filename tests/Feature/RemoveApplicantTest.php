<?php

use App\Enums\Role;
use App\Models\Applicant;
use App\Models\Application;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

test('admission personnel remove a troll applicant: account, files and personal data go', function () {
    $user = $this->approved();
    $number = $this->number($user);
    $app = $this->app_($user);
    $files = $app->allDocuments()->pluck('storage_ref');
    expect($files)->not->toBeEmpty();
    Storage::disk('local')->assertExists($files->all());

    $proctor = $this->makeUser(Role::Admission, ['email' => 'proctor@plpasig.edu.ph']);
    $proctor->forceFill(['task' => 'proctor'])->save();
    $this->asUser($proctor)->deleteJson("/api/admin/applicants/{$number}", ['reason' => 'Fake account with joke files.'])->assertForbidden();
    $this->asUser($this->officer())->deleteJson("/api/admin/applicants/{$number}", ['reason' => 'short'])->assertUnprocessable();
    $this->asUser($this->officer())->deleteJson("/api/admin/applicants/{$number}", ['reason' => 'Fake account with joke files.'])->assertOk();

    // Gone from the office, the files erased, the seat freed
    Storage::disk('local')->assertMissing($files->all());
    expect(Application::find($app->id))->toBeNull()
        ->and($app->allDocuments()->count())->toBe(0)
        ->and($app->examRegistration()->count())->toBe(0);
    $this->asUser($this->officer())->getJson('/api/admin/state')->assertOk()->assertDontSee($number);

    // Personal data wiped, the login gone (the old token stops working)
    $applicant = Applicant::withTrashed()->find($app->applicant_id);
    $account = User::withTrashed()->find($user->id);
    expect($applicant->trashed())->toBeTrue()->and($applicant->last_name)->toBe('Removed')->and($applicant->phone)->toBeNull()
        ->and($account->trashed())->toBeTrue()->and($account->email)->not->toBe('jordan@example.com');
    $this->asUser($user)->getJson('/api/applicant/portal')->assertUnauthorized();

    // The audit log keeps who removed it and why
    $this->assertDatabaseHas('audit_logs', ['action' => 'applicant.removed', 'application_id' => $app->id, 'user_id' => $this->officer()->id]);

    // The email is free again
    $this->postJson('/api/auth/register', [
        'first_name' => 'Jordan', 'last_name' => 'Mendoza', 'email' => 'jordan@example.com', 'phone' => '+63 917 123 4567',
        'password' => 'secret123', 'password_confirmation' => 'secret123', 'terms' => true,
    ])->assertCreated();
});

test('an admitted applicant cannot be removed', function () {
    $user = $this->admitted();
    $this->asUser($this->officer())->deleteJson("/api/admin/applicants/{$this->number($user)}", ['reason' => 'Fake account with joke files.'])->assertUnprocessable();
    expect(User::find($user->id))->not->toBeNull();
});
