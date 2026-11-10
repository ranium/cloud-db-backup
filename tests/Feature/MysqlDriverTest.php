<?php

use App\Backup\Age;
use App\Backup\BackupException;
use App\Backup\ClusterConfig;
use App\Backup\Drivers\MysqlDriver;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

function mysqlCluster(array $overrides = []): ClusterConfig
{
    return new ClusterConfig(...[
        'name' => 'main',
        'driver' => 'mysql',
        'host' => 'db.example.test',
        'port' => 3306,
        'username' => 'admin',
        'password' => 'pa"ss\\word',
        ...$overrides,
    ]);
}

function tempDir(): string
{
    $dir = sys_get_temp_dir().'/cloud-db-backup-driver-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);

    return $dir;
}

it('keeps the password in a private credentials file, never on the command line', function () {
    Process::fake(['*' => Process::result("app\nmysql\n")]);
    $driver = new MysqlDriver(mysqlCluster(), $dir = tempDir(), 60);

    $driver->databases();

    $file = realpath(glob($dir.'/my-*')[0]);
    expect(substr(sprintf('%o', fileperms($file)), -4))->toBe('0600')
        ->and(file_get_contents($file))->toContain('password="pa\\"ss\\\\word"');

    Process::assertRan(function (PendingProcess $process) use ($file) {
        $command = implode(' ', $process->command);

        return $process->command[1] === '--defaults-extra-file='.$file && ! str_contains($command, 'pa"ss');
    });

    $driver->close();
    expect(glob($dir.'/my-*'))->toBe([]);
});

it('treats bad credentials as permanent and connection errors as transient', function () {
    $driver = new MysqlDriver(mysqlCluster(), tempDir(), 60);

    expect($driver->isPermanent("ERROR 1045 (28000): Access denied for user 'root'@'localhost'"))->toBeTrue()
        ->and($driver->isPermanent('ERROR 1044 (42000): Access denied for user to database'))->toBeTrue()
        ->and($driver->isPermanent('ERROR 9001 (HY000): Max connect timeout reached while reaching hostgroup 75010'))->toBeFalse()
        ->and($driver->isPermanent("ERROR 2003 (HY000): Can't connect to MySQL server"))->toBeFalse();
});

it('leaves the password out of the credentials file when there is none', function () {
    Process::fake();
    new MysqlDriver(mysqlCluster(['password' => '']), $dir = tempDir(), 60);

    expect(file_get_contents(glob($dir.'/my-*')[0]))->not->toContain('password');
});

it('lists databases without the system ones', function () {
    Process::fake(['*' => Process::result("information_schema\nlaratix_production\nmysql\nperformance_schema\nsys\npollstr_production\n")]);

    expect((new MysqlDriver(mysqlCluster(), tempDir(), 60))->databases())->toBe(['laratix_production', 'pollstr_production']);
});

it('dumps a consistent snapshot without table locks', function () {
    Process::fake();

    (new MysqlDriver(mysqlCluster(), tempDir(), 60))->dump('laratix_production', '/tmp/out.raw');

    Process::assertRan(fn (PendingProcess $process) => $process->command[0] === 'mysqldump'
        && in_array('--single-transaction', $process->command, true)
        && in_array('--routines', $process->command, true)
        && in_array('--result-file=/tmp/out.raw', $process->command, true)
        && end($process->command) === 'laratix_production');
});

it('reports a failed dump with the client error', function () {
    Process::fake(['*' => Process::result(errorOutput: "mysqldump: Got error: 1045: Access denied\n", exitCode: 2)]);

    (new MysqlDriver(mysqlCluster(), tempDir(), 60))->dump('app', '/tmp/out.raw');
})->throws(BackupException::class, 'mysqldump of app failed (exit 2): mysqldump: Got error: 1045: Access denied');

it('adds --ssl when the cluster asks for it', function () {
    Process::fake();

    (new MysqlDriver(mysqlCluster(['ssl' => 'true']), tempDir(), 60))->ping();

    Process::assertRan(fn (PendingProcess $process) => $process->command[2] === '--ssl');
});

it('accepts a complete dump and rejects a truncated or empty one', function () {
    $driver = new MysqlDriver(mysqlCluster(), $dir = tempDir(), 60);

    file_put_contents($complete = $dir.'/complete.sql', "CREATE TABLE t (id int);\n\n-- Dump completed on 2026-11-10 10:09:32\n");
    file_put_contents($truncated = $dir.'/truncated.sql', "CREATE TABLE t (id int);\nINSERT INTO t VALUES (1),(2");
    touch($empty = $dir.'/empty.sql');

    $driver->verify($complete);

    expect(fn () => $driver->verify($truncated))->toThrow(BackupException::class, 'incomplete')
        ->and(fn () => $driver->verify($empty))->toThrow(BackupException::class, 'empty');
});

it('strips the MariaDB sandbox line, compresses, and hands the dump to age', function () {
    $dir = tempDir();

    // A stand-in for age: honours "-o <file>" and copies stdin there.
    file_put_contents($fakeAge = $dir.'/age', "#!/bin/sh\nwhile [ \$# -gt 0 ]; do case \"\$1\" in -o) out=\"\$2\"; shift;; esac; shift; done\ncat > \"\$out\"\n");
    chmod($fakeAge, 0755);

    file_put_contents($dump = $dir.'/dump.sql', implode("\n", [
        '/*M!999999\- enable the sandbox mode */ ',
        '-- MariaDB dump 10.19',
        'INSERT INTO notes VALUES (\'enable the sandbox mode\');',
        '-- Dump completed on 2026-11-10 10:09:32',
        '',
    ]));

    (new MysqlDriver(mysqlCluster(), $dir, 60))->encrypt($dump, $out = $dir.'/dump.sql.gz.age', new Age($fakeAge, ['age1a', 'age1b']));

    expect(gzdecode((string) file_get_contents($out)))->toBe(implode("\n", [
        '-- MariaDB dump 10.19',
        'INSERT INTO notes VALUES (\'enable the sandbox mode\');',
        '-- Dump completed on 2026-11-10 10:09:32',
        '',
    ]));
});
