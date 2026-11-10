<?php

namespace App\Backup;

interface Uploader
{
    /**
     * Upload a local file to the backup bucket under $key.
     *
     * @throws BackupException
     */
    public function put(string $localPath, string $key): void;
}
