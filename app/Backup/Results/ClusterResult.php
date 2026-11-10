<?php

namespace App\Backup\Results;

final readonly class ClusterResult
{
    /**
     * @param  list<DatabaseResult>  $databases
     * @param  string|null  $error  Set when the cluster as a whole failed.
     */
    public function __construct(
        public string $cluster,
        public ?string $driver,
        public array $databases = [],
        public ?string $error = null,
    ) {}

    public function successful(): bool
    {
        if ($this->error !== null) {
            return false;
        }

        foreach ($this->databases as $database) {
            if (! $database->successful) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'cluster' => $this->cluster,
            'driver' => $this->driver,
            'successful' => $this->successful(),
            'error' => $this->error,
            'databases' => array_map(fn (DatabaseResult $database): array => $database->toArray(), $this->databases),
        ];
    }
}
