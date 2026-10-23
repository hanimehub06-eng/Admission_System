<?php

namespace App\Services\Security;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\Log;

/**
 * ClamAV daemon (clamd) over its INSTREAM protocol, on the same server
 * (CLAMAV_SOCKET=unix:///var/run/clamav/clamd.ctl or tcp://127.0.0.1:3310).
 * Fails closed: if clamd can't be reached, the upload is refused.
 */
class ClamAvScanner implements MalwareScanner
{
    private const CHUNK = 8192;

    public function __construct(
        private string $socket,
        private int $timeout,
    ) {}

    public function scan(string $path): string
    {
        $conn = @stream_socket_client($this->socket, $errno, $error, $this->timeout);
        $file = @fopen($path, 'rb');
        if (! $conn || ! $file) {
            Log::error('Virus scan unavailable', ['socket' => $this->socket, 'error' => $error ?? 'file not readable']);
            throw new BusinessRuleException("The file couldn't be checked for viruses right now. Try again in a few minutes.");
        }
        stream_set_timeout($conn, $this->timeout);

        fwrite($conn, "zINSTREAM\0");
        while (! feof($file)) {
            $chunk = fread($file, self::CHUNK);
            if ($chunk === '' || $chunk === false) {
                break;
            }
            fwrite($conn, pack('N', strlen($chunk)).$chunk);
        }
        fwrite($conn, pack('N', 0));
        $reply = trim((string) stream_get_contents($conn), "\0\r\n ");
        fclose($file);
        fclose($conn);

        if (str_ends_with($reply, 'OK')) {
            return 'clean';
        }
        if (str_ends_with($reply, 'FOUND')) {
            Log::warning('Upload rejected by the virus scan', ['reply' => $reply]);
            throw new BusinessRuleException('This file was rejected by the virus scan. Upload a different copy.');
        }

        Log::error('Unexpected virus scan reply', ['reply' => $reply]);
        throw new BusinessRuleException("The file couldn't be checked for viruses right now. Try again in a few minutes.");
    }
}
