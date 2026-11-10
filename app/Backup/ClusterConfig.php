<?php

namespace App\Backup;

use InvalidArgumentException;

/**
 * One database cluster to back up, as configured by BACKUP_CLUSTER_<NAME>_*.
 */
final readonly class ClusterConfig
{
    public const DRIVERS = ['mysql', 'pgsql'];

    /**
     * @param  list<string>  $include  When non-empty, only these databases.
     * @param  list<string>  $exclude  Databases to skip.
     */
    public function __construct(
        public string $name,
        public string $driver,
        public string $host,
        public int $port,
        public string $username,
        public string $password,
        public array $include = [],
        public array $exclude = [],
        public ?string $ssl = null,
        public string $maintenanceDatabase = 'postgres',
    ) {}

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws InvalidArgumentException when a required setting is missing.
     */
    public static function fromConfig(string $name, array $config): self
    {
        $prefix = (string) ($config['env_prefix'] ?? '');
        $driver = (string) ($config['driver'] ?? 'mysql');

        if (! in_array($driver, self::DRIVERS, true)) {
            throw new InvalidArgumentException("{$prefix}DRIVER must be one of: ".implode(', ', self::DRIVERS).", got \"{$driver}\".");
        }

        foreach (['host', 'username'] as $key) {
            if (blank($config[$key] ?? null)) {
                throw new InvalidArgumentException($prefix.strtoupper($key).' is not set.');
            }
        }

        return new self(
            name: $name,
            driver: $driver,
            host: (string) $config['host'],
            port: filled($config['port'] ?? null) ? (int) $config['port'] : ($driver === 'pgsql' ? 5432 : 3306),
            username: (string) $config['username'],
            password: (string) ($config['password'] ?? ''),
            include: array_values($config['include'] ?? []),
            exclude: array_values($config['exclude'] ?? []),
            ssl: filled($config['ssl'] ?? null) ? (string) $config['ssl'] : null,
            maintenanceDatabase: (string) ($config['maintenance_database'] ?? 'postgres'),
        );
    }

    /**
     * Apply the include/exclude lists to the databases found on the cluster.
     *
     * @param  list<string>  $databases
     * @return list<string>
     */
    public function select(array $databases): array
    {
        return array_values(array_filter(
            $databases,
            fn (string $database): bool => ($this->include === [] || in_array($database, $this->include, true))
                && ! in_array($database, $this->exclude, true),
        ));
    }
}
