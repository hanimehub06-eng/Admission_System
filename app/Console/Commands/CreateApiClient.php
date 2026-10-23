<?php

namespace App\Console\Commands;

use App\Models\ApiClient;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Sysadmin: create (or rotate) the API key another ERP system uses to call
 * us. The key is printed once; only its hash is stored.
 */
class CreateApiClient extends Command
{
    protected $signature = 'api-client:create {name : e.g. enrollment}
        {--ips= : Comma-separated IPs allowed to use the key (default: any; Nginx filters too)}
        {--disable : Switch the client off instead}';

    protected $description = 'Create or rotate an API key for another system (X-API-Key)';

    public function handle(AuditLogger $audit): int
    {
        $name = strtolower($this->argument('name'));
        if (! preg_match('/^[a-z0-9_-]{2,40}$/', $name)) {
            $this->error('Use a short name: letters, digits, - or _.');

            return self::FAILURE;
        }

        if ($this->option('disable')) {
            $updated = ApiClient::where('name', $name)->update(['is_active' => false]);
            $audit->record('api_client.disabled', "Disabled API client {$name}");
            $this->info($updated ? "Disabled {$name}." : "No client named {$name}.");

            return self::SUCCESS;
        }

        $key = Str::random(48);
        $ips = $this->option('ips') ? array_values(array_filter(array_map('trim', explode(',', $this->option('ips'))))) : null;
        $client = ApiClient::updateOrCreate(['name' => $name], [
            'key_hash' => ApiClient::hashKey($key), 'allowed_ips' => $ips, 'is_active' => true,
        ]);
        $audit->record($client->wasRecentlyCreated ? 'api_client.created' : 'api_client.rotated',
            ($client->wasRecentlyCreated ? 'Created' : 'Rotated the key of')." API client {$name}", $client);

        $this->info(($client->wasRecentlyCreated ? 'Created' : 'Rotated the key of')." API client {$name}.");
        $this->line("X-API-Key: {$key}");
        $this->line('Give it to the other system over a secure channel. It is not shown again.');

        return self::SUCCESS;
    }
}
