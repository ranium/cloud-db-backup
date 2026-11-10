<?php

use App\Backup\ClusterConfig;

/**
 * Evaluate config/backup.php against a given environment.
 *
 * @param  array<string, string>  $env
 * @return array<string, mixed>
 */
function backupConfigWith(array $env): array
{
    foreach ($env as $key => $value) {
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    try {
        return require config_path('backup.php');
    } finally {
        foreach (array_keys($env) as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }
}

it('reads any number of clusters from BACKUP_CLUSTERS', function () {
    $config = backupConfigWith([
        'BACKUP_CLUSTERS' => 'ranium-mysql-1, demos ,',
        'BACKUP_CLUSTER_RANIUM_MYSQL_1_HOST' => 'db-1.internal',
        'BACKUP_CLUSTER_RANIUM_MYSQL_1_EXCLUDE' => 'scratch, tmp',
        'BACKUP_CLUSTER_DEMOS_DRIVER' => 'pgsql',
        'BACKUP_CLUSTER_DEMOS_SSL' => 'require',
    ]);

    expect(array_keys($config['clusters']))->toBe(['ranium-mysql-1', 'demos'])
        ->and($config['clusters']['ranium-mysql-1'])->toMatchArray([
            'driver' => 'mysql',
            'host' => 'db-1.internal',
            'exclude' => ['scratch', 'tmp'],
            'env_prefix' => 'BACKUP_CLUSTER_RANIUM_MYSQL_1_',
        ])
        ->and($config['clusters']['demos'])->toMatchArray(['driver' => 'pgsql', 'ssl' => 'require']);
});

it('has no clusters when BACKUP_CLUSTERS is unset', function () {
    expect(backupConfigWith([])['clusters'])->toBe([]);
});

it('defaults the port from the driver', function () {
    $base = ['host' => 'h', 'username' => 'u', 'password' => 'p', 'env_prefix' => 'X_'];

    expect(ClusterConfig::fromConfig('a', ['driver' => 'mysql', ...$base])->port)->toBe(3306)
        ->and(ClusterConfig::fromConfig('b', ['driver' => 'pgsql', ...$base])->port)->toBe(5432)
        ->and(ClusterConfig::fromConfig('c', ['driver' => 'mysql', 'port' => '3307', ...$base])->port)->toBe(3307);
});

it('rejects an unknown driver by its env var name', function () {
    ClusterConfig::fromConfig('a', ['driver' => 'mongo', 'host' => 'h', 'username' => 'u', 'password' => 'p', 'env_prefix' => 'BACKUP_CLUSTER_A_']);
})->throws(InvalidArgumentException::class, 'BACKUP_CLUSTER_A_DRIVER must be one of: mysql, pgsql, got "mongo".');
