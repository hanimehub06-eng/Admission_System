<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Another ERP system that calls this API (X-API-Key + IP allowlist).
 * Created by the sysadmin with `php artisan api-client:create`; only the
 * key's SHA-256 is stored.
 */
#[Fillable(['name', 'key_hash', 'allowed_ips', 'is_active', 'last_used_at'])]
#[Hidden(['key_hash'])]
class ApiClient extends Model
{
    protected function casts(): array
    {
        return [
            'allowed_ips' => 'array',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public static function hashKey(string $key): string
    {
        return hash('sha256', $key);
    }

    public function allowsIp(?string $ip): bool
    {
        return empty($this->allowed_ips) || in_array($ip, $this->allowed_ips, true);
    }
}
