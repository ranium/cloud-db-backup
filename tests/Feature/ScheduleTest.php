<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

it('schedules the nightly backup at 03:00 IST by default, without overlaps', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'backup:databases'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('30 21 * * *')
        ->and($events->first()->timezone)->toBe('UTC')
        ->and($events->first()->withoutOverlapping)->toBeTrue()
        ->and($events->first()->runInBackground)->toBeTrue();
});

it('needs no database: cache, session, queue and failed jobs default to DB-free drivers', function () {
    $names = ['CACHE_STORE', 'SESSION_DRIVER', 'QUEUE_CONNECTION', 'QUEUE_FAILED_DRIVER'];
    $saved = [];

    foreach ($names as $name) {
        $saved[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
        unset($_ENV[$name], $_SERVER[$name]);
        putenv($name);
    }

    try {
        $cache = require config_path('cache.php');
        $session = require config_path('session.php');
        $queue = require config_path('queue.php');
    } finally {
        foreach ($saved as $name => [$env, $server, $process]) {
            if ($env !== null) {
                $_ENV[$name] = $env;
            }
            if ($server !== null) {
                $_SERVER[$name] = $server;
            }
            if ($process !== false) {
                putenv("{$name}={$process}");
            }
        }
    }

    expect($cache['default'])->toBe('file')
        ->and($session['driver'])->toBe('array')
        ->and($queue['default'])->toBe('sync')
        ->and($queue['failed']['driver'])->toBe('null');
});
