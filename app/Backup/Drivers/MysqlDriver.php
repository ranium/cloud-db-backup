<?php

namespace App\Backup\Drivers;

use App\Backup\Age;
use App\Backup\BackupException;
use App\Backup\ClusterConfig;

/**
 * MySQL through the mysql / mysqldump clients. Laravel Cloud images ship the
 * MariaDB 10.11 builds of both; their dumps of a MySQL 8.4 server restore
 * exactly into MySQL once the MariaDB-only first line is dropped (see encrypt()).
 */
final class MysqlDriver extends Driver
{
    /** Databases the server manages itself; never dumped. */
    public const SYSTEM_DATABASES = ['information_schema', 'performance_schema', 'mysql', 'sys'];

    /** What mysqldump writes as its very last line on success. */
    private const COMPLETION_MARKER = '-- Dump completed';

    private string $defaultsFile;

    public function __construct(ClusterConfig $cluster, string $workDir, int $timeoutSeconds)
    {
        parent::__construct($cluster, $workDir, $timeoutSeconds);

        $lines = ['[client]', 'host='.$cluster->host, 'port='.$cluster->port, 'user='.$cluster->username];

        if ($cluster->password !== '') {
            $lines[] = 'password="'.addcslashes($cluster->password, '"\\').'"';
        }

        $this->defaultsFile = $this->writePrivateFile('my-', implode("\n", [...$lines, '']));
    }

    public function ping(): ?string
    {
        $result = $this->run([...$this->client('mysql'), '--connect-timeout=10', '-N', '-B', '-e', 'SELECT 1']);

        return $result->successful() ? null : self::firstLines($result->errorOutput());
    }

    public function isPermanent(string $error): bool
    {
        // 1044: access denied to a database. 1045: wrong user or password.
        return (bool) preg_match('/ERROR (1044|1045)\b/', $error);
    }

    public function databases(): array
    {
        $result = $this->runOrFail('Listing databases', [...$this->client('mysql'), '-N', '-B', '-e', 'SHOW DATABASES']);

        return array_values(array_diff(self::lines($result->output()), self::SYSTEM_DATABASES));
    }

    public function dump(string $database, string $file): void
    {
        $this->runOrFail("mysqldump of {$database}", [
            ...$this->client('mysqldump'),
            // A consistent snapshot without locking tables (InnoDB).
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--events',
            '--hex-blob',
            '--no-tablespaces',
            '--result-file='.$file,
            $database,
        ]);
    }

    public function verify(string $file): void
    {
        $size = @filesize($file);

        if ($size === false || $size === 0) {
            throw new BackupException('The dump is empty.');
        }

        $handle = fopen($file, 'rb');
        fseek($handle, max(0, $size - 512));
        $tail = (string) stream_get_contents($handle);
        fclose($handle);

        if (! str_contains($tail, self::COMPLETION_MARKER)) {
            throw new BackupException('The dump has no "'.self::COMPLETION_MARKER.'" line, so it is incomplete.');
        }
    }

    public function encrypt(string $file, string $encryptedFile, Age $age): void
    {
        // The MariaDB client opens every dump with "/*M!999999\- enable the
        // sandbox mode */", which some MySQL clients refuse to restore. It is
        // only ever the first line, so drop it there and nowhere else.
        $this->runOrFail('Compressing and encrypting the dump', [
            'bash', '-o', 'pipefail', '-c',
            'sed "1{/enable the sandbox mode/d;}" "$1" | gzip -6 | "$2" "${@:4}" -o "$3"',
            'encrypt', $file, $age->binary(), $encryptedFile, ...$age->recipientArguments(),
        ]);
    }

    public function extension(): string
    {
        return 'sql.gz.age';
    }

    public function close(): void
    {
        @unlink($this->defaultsFile);
    }

    /**
     * The client binary with credentials attached. --defaults-extra-file must
     * be the first option or the client ignores it.
     *
     * @return list<string>
     */
    private function client(string $binary): array
    {
        $command = [$binary, '--defaults-extra-file='.$this->defaultsFile];

        if (filter_var($this->cluster->ssl, FILTER_VALIDATE_BOOLEAN)) {
            $command[] = '--ssl';
        }

        return $command;
    }
}
