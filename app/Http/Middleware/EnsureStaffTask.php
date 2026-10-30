<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `task:personnel,exam_office`: an Admission Personnel account with a task
 * (AccountController::TASKS) may use only routes listed for that task; a bare
 * `task` admits no task. Untasked personnel and other roles pass through.
 */
class EnsureStaffTask
{
    public function handle(Request $request, Closure $next, string ...$tasks): Response
    {
        $user = $request->user();
        if ($user?->task && $user->primaryRole() === Role::Admission && ! in_array($user->task, $tasks, true)) {
            return response()->json(['message' => 'Your assigned task does not include this action.'], 403);
        }

        return $next($request);
    }
}
