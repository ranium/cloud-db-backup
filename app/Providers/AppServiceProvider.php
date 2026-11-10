<?php

namespace App\Providers;

use App\Backup\Age;
use App\Backup\BackupRunner;
use App\Backup\DriverFactory;
use App\Backup\Heartbeat;
use App\Backup\S3Uploader;
use App\Backup\Uploader;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Uploader::class, fn (): Uploader => S3Uploader::fromConfig());
        $this->app->bind(Age::class, fn (): Age => Age::fromConfig());
        $this->app->bind(Heartbeat::class, fn (): Heartbeat => Heartbeat::fromConfig());

        $this->app->bind(BackupRunner::class, fn (Application $app): BackupRunner => new BackupRunner(
            $app->make(DriverFactory::class),
            fn (): Uploader => $app->make(Uploader::class),
            $app->make(Age::class),
            $app->make(Heartbeat::class),
        ));
    }
}
