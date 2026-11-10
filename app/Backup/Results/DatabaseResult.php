<?php

namespace App\Backup\Results;

final readonly class DatabaseResult
{
    /**
     * @param  list<string>  $keys  S3 keys written for this database.
     */
    public function __construct(
        public string $database,
        public bool $successful,
        public ?string $error = null,
        public int $bytes = 0,
        public ?string $sha256 = null,
        public array $keys = [],
        public float $seconds = 0.0,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'database' => $this->database,
            'successful' => $this->successful,
            'error' => $this->error,
            'bytes' => $this->bytes,
            'sha256' => $this->sha256,
            'keys' => $this->keys,
            'seconds' => round($this->seconds, 1),
        ];
    }
}
