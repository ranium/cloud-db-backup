<?php

namespace App\Backup;

use Aws\S3\MultipartUploader;
use Aws\S3\S3Client;
use Throwable;

/**
 * Uploads straight to S3 Standard-IA.
 *
 * Talks to the SDK directly, and never sends an ACL: the IAM user this runs as
 * may only write under its prefix (PutObject and the multipart calls), and the
 * bucket has ACLs disabled. The SDK's ObjectUploader always sends one, which
 * is why it is not used here.
 */
final readonly class S3Uploader implements Uploader
{
    public const STORAGE_CLASS = 'STANDARD_IA';

    /** Above this, upload in parts. */
    public const MULTIPART_THRESHOLD = 64 * 1024 * 1024;

    public function __construct(
        private S3Client $client,
        private string $bucket,
    ) {}

    public static function fromConfig(): self
    {
        $config = config('backup.s3');

        foreach (['bucket', 'region', 'key', 'secret'] as $key) {
            if (blank($config[$key] ?? null)) {
                throw new BackupException('BACKUP_S3_'.strtoupper($key).' is not set.');
            }
        }

        return new self(new S3Client([
            'version' => 'latest',
            'region' => $config['region'],
            'credentials' => ['key' => $config['key'], 'secret' => $config['secret']],
        ]), $config['bucket']);
    }

    public function put(string $localPath, string $key): void
    {
        try {
            if ((int) filesize($localPath) > self::MULTIPART_THRESHOLD) {
                (new MultipartUploader($this->client, $localPath, [
                    'bucket' => $this->bucket,
                    'key' => $key,
                    'params' => ['StorageClass' => self::STORAGE_CLASS],
                ]))->upload();

                return;
            }

            $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'SourceFile' => $localPath,
                'StorageClass' => self::STORAGE_CLASS,
            ]);
        } catch (Throwable $exception) {
            throw new BackupException("Upload to s3://{$this->bucket}/{$key} failed: {$exception->getMessage()}", previous: $exception);
        }
    }
}
