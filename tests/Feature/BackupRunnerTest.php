<?php

use App\Backup\Age;
use App\Backup\BackupException;
use App\Backup\BackupRunner;
use App\Backup\Heartbeat;
use App\Backup\Uploader;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\FakeDriver;
use Tests\Support\FakeDriverFactory;
use Tests\Support\FakeUploader;

beforeEach(function () {
    Carbon::setTestNow('2026-11-14 21:30:00');
    Http::fake();
});

function driverWith(array $databases): FakeDriver
{
    $driver = new FakeDriver;
    $driver->databaseNames = $databases;

    return $driver;
}

it('backs up every database on every cluster and writes a manifest', function () {
    $workDir = configureBackup(['ranium-mysql-1' => [], 'internal' => []]);
    $drivers = new FakeDriverFactory([
        'ranium-mysql-1' => driverWith(['laratix_production', 'pollstr_production']),
        'internal' => driverWith(['trackr_production']),
    ]);
    $uploader = new FakeUploader;

    $report = fakeRunner($drivers, $uploader)->run();

    expect($report->successful())->toBeTrue()
        ->and($uploader->keys('/daily/'))->toBe([
            'db/daily/ranium-mysql-1/laratix_production/2026/11/14/laratix_production-20261114T213000Z.sql.gz.age',
            'db/daily/ranium-mysql-1/pollstr_production/2026/11/14/pollstr_production-20261114T213000Z.sql.gz.age',
            'db/daily/internal/trackr_production/2026/11/14/trackr_production-20261114T213000Z.sql.gz.age',
        ])
        ->and($uploader->uploads['db/daily/internal/trackr_production/2026/11/14/trackr_production-20261114T213000Z.sql.gz.age'])
        ->toBe('encrypted(dump of trackr_production)')
        ->and($uploader->keys('/monthly/'))->toBe([])
        ->and($drivers->drivers['ranium-mysql-1']->closed)->toBeTrue()
        ->and($drivers->drivers['internal']->closed)->toBeTrue();

    $manifest = json_decode($uploader->uploads['db/manifests/test-deployment/2026/11/14/20261114T213000Z.json'], true);

    expect($manifest['successful'])->toBeTrue()
        ->and($manifest['clusters'][0]['databases'][0])->toMatchArray([
            'database' => 'laratix_production',
            'successful' => true,
            'bytes' => strlen('encrypted(dump of laratix_production)'),
            'sha256' => hash('sha256', 'encrypted(dump of laratix_production)'),
        ]);

    // Nothing is left behind on disk: neither the plain dump nor the encrypted one.
    expect(glob($workDir.'/*'))->toBe([]);
});

it('applies the include and exclude lists', function () {
    configureBackup(['a' => ['exclude' => ['scratch']], 'b' => ['include' => ['only_this']]]);
    $drivers = new FakeDriverFactory([
        'a' => driverWith(['app', 'scratch']),
        'b' => driverWith(['only_this', 'not_this']),
    ]);

    fakeRunner($drivers, new FakeUploader)->run();

    expect($drivers->drivers['a']->dumped)->toBe(['app'])
        ->and($drivers->drivers['b']->dumped)->toBe(['only_this']);
});

it('waits for a suspended cluster to wake up', function () {
    Sleep::fake(syncWithCarbon: true);
    configureBackup(['sleepy' => []]);
    $driver = driverWith(['app']);
    $driver->pings = ['Max connect timeout reached', 'Max connect timeout reached', null];

    $report = fakeRunner(new FakeDriverFactory(['sleepy' => $driver]), new FakeUploader)->run();

    expect($report->successful())->toBeTrue()
        ->and($driver->pingCount)->toBe(3)
        ->and($driver->dumped)->toBe(['app']);
    Sleep::assertSleptTimes(2);
});

it('gives up on an unreachable cluster but still backs up the others', function () {
    Sleep::fake(syncWithCarbon: true);
    configureBackup(['down' => [], 'up' => []], ['backup.wake_timeout_seconds' => 60]);
    $down = driverWith(['app']);
    $down->pings = ['Connection refused'];
    $uploader = new FakeUploader;

    $report = fakeRunner(new FakeDriverFactory(['down' => $down, 'up' => driverWith(['other'])]), $uploader)->run();

    expect($report->successful())->toBeFalse()
        ->and($report->clusters[0]->error)->toBe('Not reachable after 60s: Connection refused')
        ->and($down->dumped)->toBe([])
        ->and($down->closed)->toBeTrue()
        ->and($uploader->keys('/daily/up/other/'))->toHaveCount(1);
});

it('does not wait out the wake timeout on bad credentials', function () {
    Sleep::fake(syncWithCarbon: true);
    configureBackup(['main' => []]);
    $driver = driverWith(['app']);
    $driver->pings = ["ERROR 1045 (28000): Access denied for user 'admin'"];

    $report = fakeRunner(new FakeDriverFactory(['main' => $driver]), new FakeUploader)->run();

    expect($report->clusters[0]->error)->toBe("Cannot connect: ERROR 1045 (28000): Access denied for user 'admin'")
        ->and($driver->pingCount)->toBe(1);
    Sleep::assertNeverSlept();
});

it('keeps going when one database fails, and reports it', function () {
    configureBackup(['main' => []]);
    $driver = driverWith(['first', 'broken', 'last']);
    $driver->failingDumps = ['broken' => 'mysqldump of broken failed (exit 2): Access denied'];
    $uploader = new FakeUploader;

    $report = fakeRunner(new FakeDriverFactory(['main' => $driver]), $uploader)->run();

    expect($report->successful())->toBeFalse()
        ->and($driver->dumped)->toBe(['first', 'last'])
        ->and($uploader->keys('/daily/'))->toHaveCount(2)
        ->and($report->summary())->toContain('main/broken: mysqldump of broken failed (exit 2): Access denied');
});

it('never uploads a truncated dump', function () {
    configureBackup(['main' => []]);
    $driver = driverWith(['app']);
    $driver->truncated = ['app'];
    $uploader = new FakeUploader;

    $report = fakeRunner(new FakeDriverFactory(['main' => $driver]), $uploader)->run();

    expect($report->successful())->toBeFalse()
        ->and($uploader->keys('/daily/'))->toBe([])
        ->and($report->summary())->toContain('main/app: The dump is incomplete.');
});

it('reports a failed upload as a failed database', function () {
    configureBackup(['main' => []]);
    $uploader = new FakeUploader;
    $uploader->failWhenKeyContains = '/daily/';

    $report = fakeRunner(new FakeDriverFactory(['main' => driverWith(['app'])]), $uploader)->run();

    expect($report->successful())->toBeFalse()
        ->and($report->clusters[0]->databases[0]->error)->toContain('Upload of db/daily/main/app/');
});

it('also keeps a monthly copy on the monthly day', function () {
    Carbon::setTestNow('2026-12-01 21:30:00');
    configureBackup(['main' => []]);
    $uploader = new FakeUploader;

    fakeRunner(new FakeDriverFactory(['main' => driverWith(['app'])]), $uploader)->run();

    expect($uploader->keys('/monthly/'))->toBe(['db/monthly/main/app/2026/12/app-20261201T213000Z.sql.gz.age'])
        ->and($uploader->keys('/daily/'))->toHaveCount(1);
});

it('fails a misconfigured cluster without stopping the others', function () {
    configureBackup(['broken' => ['host' => null], 'fine' => []]);
    $uploader = new FakeUploader;

    $report = fakeRunner(new FakeDriverFactory(['fine' => driverWith(['app'])]), $uploader)->run();

    expect($report->successful())->toBeFalse()
        ->and($report->clusters[0]->error)->toBe('BACKUP_CLUSTER_BROKEN_HOST is not set.')
        ->and($uploader->keys('/daily/fine/'))->toHaveCount(1);
});

it('treats a cluster with nothing to back up as a failure', function () {
    configureBackup(['main' => ['include' => ['typo']]]);

    $report = fakeRunner(new FakeDriverFactory(['main' => driverWith(['app'])]), new FakeUploader)->run();

    expect($report->successful())->toBeFalse()
        ->and($report->clusters[0]->error)->toContain('No databases to back up');
});

it('fails when no clusters are configured', function () {
    configureBackup([]);

    $report = fakeRunner(new FakeDriverFactory, new FakeUploader)->run();

    expect($report->successful())->toBeFalse()
        ->and($report->errors)->toBe(['BACKUP_CLUSTERS is empty: there is nothing to back up.']);
});

it('refuses to run without usable encryption or S3 settings', function () {
    configureBackup(['main' => []]);
    $driver = driverWith(['app']);

    $runner = new BackupRunner(
        new FakeDriverFactory(['main' => $driver]),
        fn (): Uploader => throw new BackupException('BACKUP_S3_BUCKET is not set.'),
        new Age('/nonexistent/age', []),
        new Heartbeat(null),
    );

    $report = $runner->run();

    expect($report->successful())->toBeFalse()
        ->and($report->errors)->toContain('BACKUP_AGE_RECIPIENTS is not set.', 'BACKUP_S3_BUCKET is not set.')
        ->and($driver->pingCount)->toBe(0);
});

it('dry-runs without dumping, uploading or pinging', function () {
    configureBackup(['main' => []]);
    $driver = driverWith(['app', 'other']);
    $uploader = new FakeUploader;

    $report = fakeRunner(new FakeDriverFactory(['main' => $driver]), $uploader, 'https://hc-ping.com/abc')->run(dryRun: true);

    expect($report->successful())->toBeTrue()
        ->and(array_map(fn ($database) => $database->database, $report->clusters[0]->databases))->toBe(['app', 'other'])
        ->and($driver->dumped)->toBe([])
        ->and($uploader->uploads)->toBe([]);
    Http::assertNothingSent();
});

it('pings the healthcheck on start and on success', function () {
    configureBackup(['main' => []]);

    fakeRunner(new FakeDriverFactory(['main' => driverWith(['app'])]), new FakeUploader, 'https://hc-ping.com/abc')->run();

    Http::assertSentInOrder([
        fn (Request $request) => $request->url() === 'https://hc-ping.com/abc/start',
        fn (Request $request) => $request->url() === 'https://hc-ping.com/abc' && str_contains($request->body(), 'Backup succeeded'),
    ]);
});

it('pings the healthcheck fail endpoint with the reason', function () {
    configureBackup(['main' => []]);
    $driver = driverWith(['app']);
    $driver->failingDumps = ['app' => 'boom'];

    fakeRunner(new FakeDriverFactory(['main' => $driver]), new FakeUploader, 'https://hc-ping.com/abc')->run();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://hc-ping.com/abc/fail' && str_contains($request->body(), 'main/app: boom'));
});

it('sends nothing when no healthcheck is configured', function () {
    configureBackup(['main' => []]);

    fakeRunner(new FakeDriverFactory(['main' => driverWith(['app'])]), new FakeUploader)->run();

    Http::assertNothingSent();
});

it('rejects a cluster name that is not configured', function () {
    configureBackup(['main' => []]);

    $report = fakeRunner(new FakeDriverFactory(['main' => driverWith(['app'])]), new FakeUploader)->run(['mian']);

    expect($report->successful())->toBeFalse()
        ->and($report->errors)->toBe(['"mian" is not listed in BACKUP_CLUSTERS.']);
});

it('runs from the console and exits non-zero on failure', function () {
    configureBackup(['main' => []]);
    $driver = driverWith(['app']);
    $driver->failingDumps = ['app' => 'boom'];
    $uploader = new FakeUploader;
    app()->instance(BackupRunner::class, fakeRunner(new FakeDriverFactory(['main' => $driver]), $uploader));

    $this->artisan('backup:databases')->assertExitCode(1);

    $driver->failingDumps = [];
    $this->artisan('backup:databases')->assertExitCode(0);
});

it('leaves nothing on disk when a database fails', function () {
    $workDir = configureBackup(['main' => []]);
    $driver = driverWith(['truncated', 'unencryptable']);
    $driver->truncated = ['truncated'];
    $uploader = new FakeUploader;
    $uploader->failWhenKeyContains = 'unencryptable';

    fakeRunner(new FakeDriverFactory(['main' => $driver]), $uploader)->run();

    expect(glob($workDir.'/*'))->toBe([]);
});

it('removes the current dump when the run is abandoned mid-way', function () {
    $workDir = configureBackup(['main' => []]);
    $driver = driverWith(['app']);
    $runner = fakeRunner(new FakeDriverFactory(['main' => $driver]), new FakeUploader);
    $leftBehind = null;
    $driver->afterDump = function (string $file) use ($runner, &$leftBehind) {
        $leftBehind = file_exists($file);
        $runner->abandon();
    };

    $runner->run();

    expect($leftBehind)->toBeTrue()
        ->and($driver->closed)->toBeTrue()
        ->and(glob($workDir.'/*'))->toBe([]);
});

it('sweeps files a killed run left behind, but only old ones', function () {
    $workDir = configureBackup(['main' => []]);
    mkdir($workDir, 0700, true);
    touch($stale = $workDir.'/main-app-abc.raw', time() - 90000);
    touch($fresh = $workDir.'/my-other-run', time() - 60);

    fakeRunner(new FakeDriverFactory(['main' => driverWith(['app'])]), new FakeUploader)->run();

    expect(file_exists($stale))->toBeFalse()
        ->and(file_exists($fresh))->toBeTrue();
});

it('still reports a failure when something outside a cluster breaks', function () {
    configureBackup(['main' => []], ['backup.work_dir' => '/dev/null/not-a-directory']);

    $report = fakeRunner(new FakeDriverFactory(['main' => driverWith(['app'])]), new FakeUploader, 'https://hc-ping.com/abc')->run();

    expect($report->successful())->toBeFalse()
        ->and($report->errors[0])->toStartWith('Unexpected error:');
    Http::assertSent(fn (Request $request) => $request->url() === 'https://hc-ping.com/abc/fail');
});
