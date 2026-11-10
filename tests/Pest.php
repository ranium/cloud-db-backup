<?php

use App\Backup\Age;
use App\Backup\BackupRunner;
use App\Backup\Heartbeat;
use Tests\Support\FakeDriverFactory;
use Tests\Support\FakeUploader;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/**
 * Point config at a fresh work dir and a known set of clusters.
 *
 * @param  array<string, array<string, mixed>>  $clusters
 */
function configureBackup(array $clusters, array $overrides = []): string
{
    $workDir = sys_get_temp_dir().'/cloud-db-backup-test-'.bin2hex(random_bytes(4));

    $defaults = static fn (string $name, array $cluster): array => [
        'driver' => 'mysql',
        'host' => "{$name}.example.test",
        'port' => null,
        'username' => 'admin',
        'password' => 'secret',
        'include' => [],
        'exclude' => [],
        'ssl' => null,
        'maintenance_database' => 'postgres',
        'env_prefix' => 'BACKUP_CLUSTER_'.strtoupper(str_replace('-', '_', $name)).'_',
        ...$cluster,
    ];

    config([
        'backup.deployment' => 'test-deployment',
        'backup.clusters' => array_map(fn (string $name): array => $defaults($name, $clusters[$name]), array_combine(array_keys($clusters), array_keys($clusters))),
        'backup.work_dir' => $workDir,
        'backup.wake_timeout_seconds' => 300,
        'backup.wake_retry_seconds' => 10,
        'backup.monthly_day' => 1,
        'backup.s3.prefix' => 'db',
        ...$overrides,
    ]);

    return $workDir;
}

/**
 * A runner wired to fakes. The age binary only has to be executable, since
 * the fake drivers never call it.
 */
function fakeRunner(FakeDriverFactory $drivers, FakeUploader $uploader, ?string $healthcheckUrl = null): BackupRunner
{
    return new BackupRunner(
        $drivers,
        fn (): FakeUploader => $uploader,
        new Age(PHP_BINARY, ['age1testrecipient']),
        new Heartbeat($healthcheckUrl),
    );
}
