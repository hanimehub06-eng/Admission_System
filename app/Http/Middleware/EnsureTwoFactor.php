<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * NFR-04: with STAFF_2FA_REQUIRED on, staff routes accept only a token
 * issued after the 2FA step (JWT claim "tfa").
 */
class EnsureTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('admission.staff_2fa_required') && ! auth('api')->payload()->get('tfa')) {
            return response()->json(['message' => 'Sign in again with your two-factor code.'], 403);
        }

        return $next($request);
    }
}
