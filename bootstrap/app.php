<?php

use App\Exceptions\BusinessRuleException;
use App\Http\Middleware\EnsureTwoFactor;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',   // UptimeRobot / AWS load balancer health check
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'active' => EnsureUserIsActive::class,
            'two_factor' => EnsureTwoFactor::class,
        ]);
        $middleware->throttleApi('api');
        $middleware->append(SecurityHeaders::class);
        // Proxy trust (Nginx / AWS load balancer) is set from config in AppServiceProvider
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(fn (BusinessRuleException $e) => response()->json(['message' => $e->getMessage()], 422));
        // RBAC (NFR-05): a plain sentence instead of the package's wording
        $exceptions->render(fn (UnauthorizedException $e, Request $request) => $request->is('api/*')
            ? response()->json(['message' => "Your role can't do this. Ask the Admission Head or the system administrator."], 403)
            : null);
    })->create();
