<?php

namespace App\Providers;

use App\Enums\Role;
use App\Listeners\SendAdmissionNotices;
use App\Services\Dms\DmsClient;
use App\Services\Dms\FakeDmsClient;
use App\Services\Dms\HttpDmsClient;
use App\Services\Security\ClamAvScanner;
use App\Services\Security\MalwareScanner;
use App\Services\Security\NullMalwareScanner;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Opcodes\LogViewer\Facades\LogViewer;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // INT-01: fake until the DMS team publishes the API (plan §0.6)
        $this->app->singleton(DmsClient::class, fn () => match (config('admission.dms.driver')) {
            'fake' => new FakeDmsClient(config('admission.documents.local_disk')),
            'http' => new HttpDmsClient((string) config('admission.dms.base_url'), (string) config('admission.dms.api_key'), (int) config('admission.dms.timeout')),
            default => throw new InvalidArgumentException('DMS_DRIVER must be fake or http.'),
        });

        $this->app->singleton(MalwareScanner::class, fn () => match (config('admission.scanner.driver')) {
            'clamav' => new ClamAvScanner(config('admission.scanner.clamav_socket'), (int) config('admission.scanner.timeout')),
            'none' => new NullMalwareScanner,
            default => throw new InvalidArgumentException('MALWARE_SCANNER must be clamav or none.'),
        });
    }

    public function boot(): void
    {
        Event::subscribe(SendAdmissionNotices::class);

        // On AWS, TLS ends at Nginx or a load balancer: trust its X-Forwarded-* headers
        // so client IPs (audit, rate limits), https URLs and signed links are right.
        if ($proxies = config('admission.trusted_proxies')) {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);
        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(240)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));

        // The portal has no reset screen yet; the link opens the portal with the token (UI pending)
        ResetPassword::createUrlUsing(fn ($user, string $token) => url('/').'?reset='.$token.'&email='.urlencode($user->email));

        // Pulse and Log Viewer (INT-06): local machine, or the sysadmin over the VPN allowlist (Nginx)
        Gate::define('viewOperations', fn ($user = null) => $this->app->environment('local')
            || ($user && $user->hasRole(Role::Sysadmin->value)));
        Gate::define('viewPulse', fn ($user = null) => Gate::allows('viewOperations'));
        LogViewer::auth(fn () => Gate::allows('viewOperations'));
    }
}
