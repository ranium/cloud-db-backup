<?php

use App\Backup\ClusterConfig;
use App\Backup\Drivers\PostgresDriver;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

function pgCluster(array $overrides = []): ClusterConfig
{
    return new ClusterConfig(...[
        'name' => 'demos',
        'driver' => 'pgsql',
        'host' => 'ep-proud.example.test',
        'port' => 5432,
        'username' => 'laravel',
        'password' => 'p:ss\\word',
        'ssl' => 'require',
        'maintenanceDatabase' => 'neondb',
        ...$overrides,
    ]);
}

function pgTempDir(): string
{
    $dir = sys_get_temp_dir().'/cloud-db-backup-pg-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);

    return $dir;
}

it('authenticates through a private escaped pgpass file and the configured sslmode', function () {
    Process::fake(['*' => Process::result("main\nkognit\n")]);
    $driver = new PostgresDriver(pgCluster(), $dir = pgTempDir(), 60);

    expect($driver->databases())->toBe(['main', 'kognit']);

    $file = realpath(glob($dir.'/pg-*')[0]);
    expect(file_get_contents($file))->toBe("ep-proud.example.test:5432:*:laravel:p\\:ss\\\\word\n")
        ->and(substr(sprintf('%o', fileperms($file)), -4))->toBe('0600');

    Process::assertRan(fn (PendingProcess $process) => $process->environment['PGPASSFILE'] === $file
        && $process->environment['PGSSLMODE'] === 'require'
        && in_array('--dbname=neondb', $process->command, true)
        && ! str_contains(implode(' ', $process->command), 'p:ss'));

    $driver->close();
    expect(glob($dir.'/pg-*'))->toBe([]);
});

it('dumps in the custom format', function () {
    Process::fake();

    (new PostgresDriver(pgCluster(), pgTempDir(), 60))->dump('kognit', '/tmp/kognit.raw');

    Process::assertRan(fn (PendingProcess $process) => $process->command[0] === 'pg_dump'
        && in_array('--format=custom', $process->command, true)
        && in_array('--file=/tmp/kognit.raw', $process->command, true)
        && in_array('--dbname=kognit', $process->command, true));
});

it('verifies a dump by reading its table of contents', function () {
    Process::fake();

    (new PostgresDriver(pgCluster(), pgTempDir(), 60))->verify('/tmp/kognit.raw');

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['pg_restore', '--list', '/tmp/kognit.raw']);
});
