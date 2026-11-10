<?php

namespace App\Backup;

use App\Backup\Drivers\Driver;
use App\Backup\Results\ClusterResult;
use App\Backup\Results\DatabaseResult;
use App\Backup\Results\RunReport;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

/**
 * Backs up every database on every configured cluster.
 *
 * Failures are contained at the smallest unit they belong to: a database that
 * fails does not stop the rest of its cluster, a cluster that fails does not
 * stop the other clusters. Everything is reported in one RunReport, written to
 * S3 as the run's manifest and sent to the optional healthcheck.
 */
class BackupRunner
{
    /**
     * @param  Closure(): Uploader  $uploader  Resolved only for real runs, so a
     *                                         dry run needs no S3 credentials.
     */
    public function __construct(
        private readonly DriverFactory $drivers,
        private readonly Closure $uploader,
        private readonly Age $age,
        private readonly Heartbeat $heartbeat,
    ) {}

    /**
     * @param  list<string>  $only  Limit the run to these cluster names.
     * @param  bool  $dryRun  Connect and list databases; dump and upload nothing.
     */
    public function run(array $only = [], bool $dryRun = false): RunReport
    {
        $startedAt = CarbonImmutable::now('UTC');
        $previousUmask = umask(0077);

        if (! $dryRun) {
            $this->heartbeat->start();
        }

        try {
            [$clusters, $errors] = $this->selectClusters($only);
            $uploader = null;

            if (! $dryRun) {
                $errors = [...$errors, ...$this->age->problems()];

                try {
                    $uploader = ($this->uploader)();
                } catch (BackupException $exception) {
                    $errors[] = $exception->getMessage();
                }
            }

            $results = [];

            if ($errors === []) {
                $workDir = $this->workDir();

                foreach ($clusters as $name => $config) {
                    $results[] = $this->backupCluster($name, $config, $workDir, $uploader, $startedAt);
                }
            }

            $report = new RunReport(config('backup.deployment'), $startedAt, CarbonImmutable::now('UTC'), $results, $errors, $dryRun);

            if ($uploader !== null) {
                $report = $this->writeManifest($report, $uploader);
            }
        } finally {
            umask($previousUmask);
        }

        $report->successful() ? Log::info($report->summary()) : Log::error($report->summary());

        if (! $dryRun) {
            $report->successful() ? $this->heartbeat->success($report->summary()) : $this->heartbeat->fail($report->summary());
        }

        return $report;
    }

    /**
     * @param  list<string>  $only
     * @return array{0: array<string, array<string, mixed>>, 1: list<string>}
     */
    private function selectClusters(array $only): array
    {
        $clusters = config('backup.clusters', []);
        $errors = [];

        if ($clusters === []) {
            return [[], ['BACKUP_CLUSTERS is empty: there is nothing to back up.']];
        }

        if ($only !== []) {
            foreach (array_diff($only, array_keys($clusters)) as $unknown) {
                $errors[] = "\"{$unknown}\" is not listed in BACKUP_CLUSTERS.";
            }

            $clusters = array_intersect_key($clusters, array_flip($only));
        }

        return [$clusters, $errors];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function backupCluster(string $name, array $config, string $workDir, ?Uploader $uploader, CarbonImmutable $startedAt): ClusterResult
    {
        try {
            $cluster = ClusterConfig::fromConfig($name, $config);
        } catch (Throwable $exception) {
            return new ClusterResult($name, $config['driver'] ?? null, error: $exception->getMessage());
        }

        $databases = [];
        $driver = null;

        try {
            $driver = $this->drivers->make($cluster, $workDir, (int) config('backup.dump_timeout_seconds'));

            $unreachable = $this->waitUntilReachable($driver);

            if ($unreachable !== null) {
                return new ClusterResult($name, $cluster->driver, error: $driver->isPermanent($unreachable)
                    ? "Cannot connect: {$unreachable}"
                    : sprintf('Not reachable after %ds: %s', config('backup.wake_timeout_seconds'), $unreachable));
            }

            $selected = $cluster->select($driver->databases());

            if ($selected === []) {
                return new ClusterResult($name, $cluster->driver, error: 'No databases to back up. Check the cluster\'s INCLUDE and EXCLUDE lists.');
            }

            foreach ($selected as $database) {
                $databases[] = $uploader === null
                    ? new DatabaseResult($database, true)
                    : $this->backupDatabase($cluster, $driver, $database, $workDir, $uploader, $startedAt);
            }

            return new ClusterResult($name, $cluster->driver, $databases);
        } catch (Throwable $exception) {
            return new ClusterResult($name, $cluster->driver, $databases, $exception->getMessage());
        } finally {
            $driver?->close();
        }
    }

    /**
     * A suspended cluster refuses connections until it has woken up, which
     * has been seen to take minutes. Null once it answers, otherwise the last
     * error after the wake timeout.
     */
    private function waitUntilReachable(Driver $driver): ?string
    {
        $deadline = CarbonImmutable::now()->addSeconds((int) config('backup.wake_timeout_seconds'));

        while (true) {
            $error = $driver->ping();

            if ($error === null) {
                return null;
            }

            if ($driver->isPermanent($error) || CarbonImmutable::now()->gte($deadline)) {
                return $error;
            }

            Sleep::for((int) config('backup.wake_retry_seconds'))->seconds();
        }
    }

    private function backupDatabase(ClusterConfig $cluster, Driver $driver, string $database, string $workDir, Uploader $uploader, CarbonImmutable $startedAt): DatabaseResult
    {
        $began = microtime(true);
        $base = $workDir.'/'.Str::slug($cluster->name.'-'.$database).'-'.Str::random(8);
        $plain = $base.'.raw';
        $encrypted = $base.'.'.$driver->extension();

        try {
            $driver->dump($database, $plain);
            $driver->verify($plain);
            $driver->encrypt($plain, $encrypted, $this->age);
            @unlink($plain);

            $keys = $this->keysFor($cluster->name, $database, $driver->extension(), $startedAt);

            foreach ($keys as $key) {
                $uploader->put($encrypted, $key);
            }

            return new DatabaseResult(
                $database,
                true,
                bytes: (int) filesize($encrypted),
                sha256: hash_file('sha256', $encrypted) ?: null,
                keys: $keys,
                seconds: microtime(true) - $began,
            );
        } catch (Throwable $exception) {
            return new DatabaseResult($database, false, $exception->getMessage(), seconds: microtime(true) - $began);
        } finally {
            @unlink($plain);
            @unlink($encrypted);
        }
    }

    /**
     * The daily key, plus the monthly one on the configured day. The bucket's
     * lifecycle rules expire each prefix on its own schedule.
     *
     * @return list<string>
     */
    private function keysFor(string $cluster, string $database, string $extension, CarbonImmutable $startedAt): array
    {
        $prefix = config('backup.s3.prefix');
        $file = $database.'-'.$startedAt->format('Ymd\THis\Z').'.'.$extension;

        $keys = ["{$prefix}/daily/{$cluster}/{$database}/{$startedAt->format('Y/m/d')}/{$file}"];

        if ($startedAt->day === (int) config('backup.monthly_day')) {
            $keys[] = "{$prefix}/monthly/{$cluster}/{$database}/{$startedAt->format('Y/m')}/{$file}";
        }

        return $keys;
    }

    private function writeManifest(RunReport $report, Uploader $uploader): RunReport
    {
        $key = sprintf(
            '%s/manifests/%s/%s/%s.json',
            config('backup.s3.prefix'),
            $report->deployment,
            $report->startedAt->format('Y/m/d'),
            $report->startedAt->format('Ymd\THis\Z'),
        );

        $path = $this->workDir().'/manifest-'.Str::random(8).'.json';

        try {
            file_put_contents($path, json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $uploader->put($path, $key);

            return $report;
        } catch (Throwable $exception) {
            return new RunReport(
                $report->deployment,
                $report->startedAt,
                $report->finishedAt,
                $report->clusters,
                [...$report->errors, 'Could not upload the run manifest: '.$exception->getMessage()],
            );
        } finally {
            @unlink($path);
        }
    }

    private function workDir(): string
    {
        $dir = (string) config('backup.work_dir');

        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        return $dir;
    }
}
