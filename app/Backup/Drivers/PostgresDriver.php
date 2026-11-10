<?php

namespace App\Backup\Drivers;

use App\Backup\Age;
use App\Backup\ClusterConfig;

/**
 * PostgreSQL through psql / pg_dump / pg_restore. Dumps use the custom format,
 * which is already compressed and is restored with pg_restore.
 */
final class PostgresDriver extends Driver
{
    private string $passFile;

    public function __construct(ClusterConfig $cluster, string $workDir, int $timeoutSeconds)
    {
        parent::__construct($cluster, $workDir, $timeoutSeconds);

        $escape = static fn (string $value): string => addcslashes($value, ':\\');

        $this->passFile = $this->writePrivateFile('pg-', implode(':', [
            $escape($cluster->host),
            $cluster->port,
            '*',
            $escape($cluster->username),
            $escape($cluster->password),
        ])."\n");
    }

    public function ping(): ?string
    {
        $result = $this->run($this->psql($this->cluster->maintenanceDatabase, 'SELECT 1'), $this->env());

        return $result->successful() ? null : self::firstLines($result->errorOutput());
    }

    public function isPermanent(string $error): bool
    {
        return str_contains($error, 'password authentication failed')
            || (bool) preg_match('/database ".*" does not exist/', $error);
    }

    public function databases(): array
    {
        $result = $this->runOrFail('Listing databases', $this->psql(
            $this->cluster->maintenanceDatabase,
            'SELECT datname FROM pg_database WHERE NOT datistemplate AND datallowconn ORDER BY datname',
        ), $this->env());

        return self::lines($result->output());
    }

    public function dump(string $database, string $file): void
    {
        $this->runOrFail("pg_dump of {$database}", [
            'pg_dump', ...$this->connection(), '--format=custom', '--file='.$file, '--dbname='.$database,
        ], $this->env());
    }

    public function verify(string $file): void
    {
        // pg_restore reads the whole table of contents; a truncated archive fails.
        $this->runOrFail('Checking the dump with pg_restore --list', ['pg_restore', '--list', $file]);
    }

    public function encrypt(string $file, string $encryptedFile, Age $age): void
    {
        $this->runOrFail('Encrypting the dump', [
            $age->binary(), ...$age->recipientArguments(), '-o', $encryptedFile, $file,
        ]);
    }

    public function extension(): string
    {
        return 'dump.age';
    }

    public function close(): void
    {
        @unlink($this->passFile);
    }

    /**
     * @return list<string>
     */
    private function connection(): array
    {
        return ['--host='.$this->cluster->host, '--port='.$this->cluster->port, '--username='.$this->cluster->username, '--no-password'];
    }

    /**
     * @return list<string>
     */
    private function psql(string $database, string $sql): array
    {
        return ['psql', ...$this->connection(), '--dbname='.$database, '--no-psqlrc', '--tuples-only', '--no-align', '--command='.$sql];
    }

    /**
     * @return array<string, string>
     */
    private function env(): array
    {
        $env = ['PGPASSFILE' => $this->passFile, 'PGCONNECT_TIMEOUT' => '10'];

        if ($this->cluster->ssl !== null) {
            $env['PGSSLMODE'] = $this->cluster->ssl;
        }

        return $env;
    }
}
