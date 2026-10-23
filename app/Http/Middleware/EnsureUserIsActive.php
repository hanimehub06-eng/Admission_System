<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A deactivated account loses access at once, even with an unexpired token. */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->is_active) {
            auth('api')->logout();

            return response()->json(['message' => 'This account is disabled. Contact the admission office.'], 403);
        }

        return $next($request);
    }
}
