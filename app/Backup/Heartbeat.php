<?php

namespace App\Backup;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Optional healthchecks.io pings. With no URL configured every method is a
 * no-op, and a ping that fails is logged, never allowed to fail the backup.
 */
final readonly class Heartbeat
{
    public function __construct(private ?string $url) {}

    public static function fromConfig(): self
    {
        $url = config('backup.healthcheck_url');

        return new self(filled($url) ? rtrim((string) $url, '/') : null);
    }

    public function start(): void
    {
        $this->ping('/start');
    }

    public function success(string $summary): void
    {
        $this->ping('', $summary);
    }

    public function fail(string $summary): void
    {
        $this->ping('/fail', $summary);
    }

    private function ping(string $suffix, string $body = ''): void
    {
        if ($this->url === null) {
            return;
        }

        try {
            Http::timeout(10)->retry(3, 2000, throw: false)->withBody($body, 'text/plain')->post($this->url.$suffix);
        } catch (Throwable $exception) {
            Log::warning('Healthcheck ping failed: '.$exception->getMessage());
        }
    }
}
