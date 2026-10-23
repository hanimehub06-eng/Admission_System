<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The link in the verification email (FR-01). Opened in a browser without
 * a session (the portal signs in with a JWT), so the signed URL is the
 * proof; afterwards the applicant lands back on the portal.
 */
class VerifyEmailController extends Controller
{
    public function __invoke(Request $request, string $id, string $hash, AuditLogger $audit): RedirectResponse
    {
        $user = User::findOrFail($id);
        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403, 'This link is not valid.');

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            $audit->record('auth.email_verified', 'Confirmed the email address', $user, actor: $user);
        }

        return redirect('/?verified=1');
    }
}
