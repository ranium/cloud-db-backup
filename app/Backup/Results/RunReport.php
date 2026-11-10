<?php

namespace App\Backup\Results;

use Carbon\CarbonImmutable;

final readonly class RunReport
{
    /**
     * @param  list<ClusterResult>  $clusters
     * @param  list<string>  $errors  Run-level failures (configuration, manifest).
     */
    public function __construct(
        public string $deployment,
        public CarbonImmutable $startedAt,
        public CarbonImmutable $finishedAt,
        public array $clusters,
        public array $errors = [],
        public bool $dryRun = false,
    ) {}

    public function successful(): bool
    {
        if ($this->errors !== [] || $this->clusters === []) {
            return false;
        }

        foreach ($this->clusters as $cluster) {
            if (! $cluster->successful()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Plain-text summary for the console and the healthcheck ping body.
     */
    public function summary(): string
    {
        $lines = [sprintf(
            '%s %s for %s: %d cluster(s), %d database(s) %s, %d failed, %ss.',
            $this->dryRun ? 'Dry run' : 'Backup',
            $this->successful() ? 'succeeded' : 'FAILED',
            $this->deployment,
            count($this->clusters),
            $this->count(true),
            $this->dryRun ? 'found' : 'backed up',
            $this->count(false),
            round($this->finishedAt->diffInSeconds($this->startedAt, absolute: true), 1),
        )];

        foreach ($this->errors as $error) {
            $lines[] = "- {$error}";
        }

        foreach ($this->clusters as $cluster) {
            if ($cluster->error !== null) {
                $lines[] = "- {$cluster->cluster}: {$cluster->error}";
            }

            foreach ($cluster->databases as $database) {
                if (! $database->successful) {
                    $lines[] = "- {$cluster->cluster}/{$database->database}: {$database->error}";
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'deployment' => $this->deployment,
            'started_at' => $this->startedAt->toIso8601ZuluString(),
            'finished_at' => $this->finishedAt->toIso8601ZuluString(),
            'successful' => $this->successful(),
            'errors' => $this->errors,
            'clusters' => array_map(fn (ClusterResult $cluster): array => $cluster->toArray(), $this->clusters),
        ];
    }

    private function count(bool $successful): int
    {
        $count = 0;

        foreach ($this->clusters as $cluster) {
            foreach ($cluster->databases as $database) {
                $count += $database->successful === $successful ? 1 : 0;
            }
        }

        return $count;
    }
}
