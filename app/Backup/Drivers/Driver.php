<?php

namespace App\Backup\Drivers;

use App\Backup\Age;
use App\Backup\BackupException;
use App\Backup\ClusterConfig;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * One open session against a cluster: credentials are staged in a private
 * file for the lifetime of the session and removed by close(), so a password
 * never appears on a command line where `ps` could show it.
 */
abstract class Driver
{
    public function __construct(
        protected readonly ClusterConfig $cluster,
        protected readonly string $workDir,
        protected readonly int $timeoutSeconds,
    ) {}

    /**
     * Null when the cluster answers, otherwise why it did not.
     */
    abstract public function ping(): ?string;

    /**
     * Whether a ping error will never clear by waiting: bad credentials, an
     * unknown database. Anything else is treated as "still waking up".
     */
    abstract public function isPermanent(string $error): bool;

    /**
     * Every user database on the cluster, system databases excluded.
     *
     * @return list<string>
     *
     * @throws BackupException
     */
    abstract public function databases(): array;

    /**
     * @throws BackupException
     */
    abstract public function dump(string $database, string $file): void;

    /**
     * Prove the dump is complete before anything is encrypted or uploaded: a
     * truncated dump must fail loudly, never be stored as a good backup.
     *
     * @throws BackupException
     */
    abstract public function verify(string $file): void;

    /**
     * @throws BackupException
     */
    abstract public function encrypt(string $file, string $encryptedFile, Age $age): void;

    /**
     * File extension of the uploaded object, without the leading dot.
     */
    abstract public function extension(): string;

    abstract public function close(): void;

    /**
     * Write a credentials file only the current user can read.
     */
    protected function writePrivateFile(string $prefix, string $contents): string
    {
        $path = tempnam($this->workDir, $prefix);

        if ($path === false) {
            throw new BackupException("Could not create a credentials file in {$this->workDir}.");
        }

        chmod($path, 0600);
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    protected function run(array $command, array $env = []): ProcessResult
    {
        return Process::timeout($this->timeoutSeconds)->env($env)->run($command);
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     *
     * @throws BackupException
     */
    protected function runOrFail(string $what, array $command, array $env = []): ProcessResult
    {
        $result = $this->run($command, $env);

        if (! $result->successful()) {
            throw new BackupException("{$what} failed (exit {$result->exitCode()}): ".self::firstLines($result->errorOutput()));
        }

        return $result;
    }

    protected static function firstLines(string $output, int $max = 5): string
    {
        $lines = array_slice(array_filter(array_map('trim', explode("\n", $output))), 0, $max);

        return $lines === [] ? 'no error output' : implode(' | ', $lines);
    }

    /**
     * @return list<string>
     */
    protected static function lines(string $output): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $output)), fn (string $line): bool => $line !== ''));
    }
}
