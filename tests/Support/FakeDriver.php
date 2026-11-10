<?php

namespace Tests\Support;

use App\Backup\Age;
use App\Backup\BackupException;
use App\Backup\ClusterConfig;
use App\Backup\Drivers\Driver;

/**
 * A driver whose cluster is scripted by the test: how it answers pings, which
 * databases it has, and which dumps fail or come out truncated.
 */
final class FakeDriver extends Driver
{
    /** @var list<string|null> */
    public array $pings = [null];

    /** @var list<string> */
    public array $databaseNames = [];

    /** @var array<string, string> database => error message */
    public array $failingDumps = [];

    /** @var list<string> */
    public array $truncated = [];

    /** @var list<string> */
    public array $dumped = [];

    public int $pingCount = 0;

    public bool $closed = false;

    public ?string $listError = null;

    public function __construct(?ClusterConfig $cluster = null, string $workDir = '', int $timeoutSeconds = 0)
    {
        if ($cluster !== null) {
            parent::__construct($cluster, $workDir, $timeoutSeconds);
        }
    }

    public function ping(): ?string
    {
        $this->pingCount++;

        return count($this->pings) > 1 ? array_shift($this->pings) : $this->pings[0];
    }

    public function isPermanent(string $error): bool
    {
        return str_contains($error, 'Access denied');
    }

    public function databases(): array
    {
        if ($this->listError !== null) {
            throw new BackupException($this->listError);
        }

        return $this->databaseNames;
    }

    public function dump(string $database, string $file): void
    {
        if (isset($this->failingDumps[$database])) {
            throw new BackupException($this->failingDumps[$database]);
        }

        $this->dumped[] = $database;
        file_put_contents($file, in_array($database, $this->truncated, true) ? 'TRUNCATED' : "dump of {$database}");
    }

    public function verify(string $file): void
    {
        if (file_get_contents($file) === 'TRUNCATED') {
            throw new BackupException('The dump is incomplete.');
        }
    }

    public function encrypt(string $file, string $encryptedFile, Age $age): void
    {
        file_put_contents($encryptedFile, 'encrypted('.file_get_contents($file).')');
    }

    public function extension(): string
    {
        return 'sql.gz.age';
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
