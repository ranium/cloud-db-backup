<?php

namespace App\Backup;

use App\Backup\Drivers\Driver;
use App\Backup\Drivers\MysqlDriver;
use App\Backup\Drivers\PostgresDriver;

class DriverFactory
{
    public function make(ClusterConfig $cluster, string $workDir, int $timeoutSeconds): Driver
    {
        return match ($cluster->driver) {
            'pgsql' => new PostgresDriver($cluster, $workDir, $timeoutSeconds),
            default => new MysqlDriver($cluster, $workDir, $timeoutSeconds),
        };
    }
}
