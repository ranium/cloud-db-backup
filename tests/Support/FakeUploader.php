<?php

namespace Tests\Support;

use App\Backup\BackupException;
use App\Backup\Uploader;

final class FakeUploader implements Uploader
{
    /** @var array<string, string> key => uploaded contents */
    public array $uploads = [];

    /** Keys containing this string fail to upload. */
    public ?string $failWhenKeyContains = null;

    public function put(string $localPath, string $key): void
    {
        if ($this->failWhenKeyContains !== null && str_contains($key, $this->failWhenKeyContains)) {
            throw new BackupException("Upload of {$key} failed.");
        }

        $this->uploads[$key] = (string) file_get_contents($localPath);
    }

    /**
     * @return list<string>
     */
    public function keys(string $containing = ''): array
    {
        return array_values(array_filter(array_keys($this->uploads), fn (string $key): bool => str_contains($key, $containing)));
    }
}
