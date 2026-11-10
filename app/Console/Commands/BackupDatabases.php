<?php

namespace App\Console\Commands;

use App\Backup\BackupRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('backup:databases
    {--cluster=* : Only back up these clusters (names from BACKUP_CLUSTERS)}
    {--dry-run : Connect and list what would be backed up, without dumping or uploading}')]
#[Description('Dump, encrypt and upload every database on every configured cluster')]
class BackupDatabases extends Command
{
    public function handle(BackupRunner $runner): int
    {
        $report = $runner->run($this->option('cluster'), (bool) $this->option('dry-run'));

        $rows = [];

        foreach ($report->clusters as $cluster) {
            if ($cluster->error !== null) {
                $rows[] = [$cluster->cluster, '—', 'FAILED', $cluster->error];
            }

            foreach ($cluster->databases as $database) {
                $rows[] = [
                    $cluster->cluster,
                    $database->database,
                    $database->successful ? 'ok' : 'FAILED',
                    $database->successful ? $this->describe($database->bytes, $database->keys) : (string) $database->error,
                ];
            }
        }

        if ($rows !== []) {
            $this->table(['Cluster', 'Database', 'Result', 'Detail'], $rows);
        }

        $report->successful() ? $this->info($report->summary()) : $this->error($report->summary());

        return $report->successful() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<string>  $keys
     */
    private function describe(int $bytes, array $keys): string
    {
        if ($keys === []) {
            return 'would be backed up';
        }

        return number_format($bytes / 1024, 1).' KB → '.implode(', ', $keys);
    }
}
