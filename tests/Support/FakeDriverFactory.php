<?php

namespace Tests\Support;

use App\Backup\ClusterConfig;
use App\Backup\DriverFactory;
use App\Backup\Drivers\Driver;

final class FakeDriverFactory extends DriverFactory
{
    /**
     * @param  array<string, FakeDriver>  $drivers  Keyed by cluster name.
     */
    public function __construct(public array $drivers = []) {}

    public function make(ClusterConfig $cluster, string $workDir, int $timeoutSeconds): Driver
    {
        return $this->drivers[$cluster->name] ??= new FakeDriver;
    }
}
