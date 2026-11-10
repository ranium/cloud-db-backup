<?php

namespace App\Backup;

use Aws\S3\MultipartUploader;
use Aws\S3\S3Client;
use Throwable;

/**
 * Uploads to AWS S3 or any S3-compatible service (DigitalOcean Spaces,
 * Cloudflare R2, Backblaze B2, MinIO…).
 *
 * Talks to the SDK directly, and never sends an ACL: on AWS the IAM user this
 * runs as may only write under its prefix (PutObject and the multipart calls),
 * and the bucket has ACLs disabled. The SDK's ObjectUploader always sends one,
 * which is why it is not used here.
 */
final readonly class S3Uploader implements Uploader
{
    /** Above this, upload in parts. */
    public const MULTIPART_THRESHOLD = 64 * 1024 * 1024;

    /**
     * @param  string|null  $storageClass  Null sends no storage class at all.
     */
    public function __construct(
        private S3Client $client,
        private string $bucket,
        private ?string $storageClass = 'STANDARD_IA',
    ) {}

    public static function fromConfig(): self
    {
        $config = config('backup.s3');

        foreach (['bucket', 'region', 'key', 'secret'] as $key) {
            if (blank($config[$key] ?? null)) {
                throw new BackupException('BACKUP_S3_'.strtoupper($key).' is not set.');
            }
        }

        $client = [
            'version' => 'latest',
            'region' => $config['region'],
            'credentials' => ['key' => $config['key'], 'secret' => $config['secret']],
        ];

        if (filled($config['endpoint'] ?? null)) {
            $client['endpoint'] = $config['endpoint'];
            $client['use_path_style_endpoint'] = (bool) ($config['use_path_style'] ?? false);
            // Recent SDKs add CRC checksum headers to every upload, which
            // several S3-compatible providers reject. Send them only when the
            // operation requires one.
            $client['request_checksum_calculation'] = 'when_required';
            $client['response_checksum_validation'] = 'when_required';
        }

        return new self(
            new S3Client($client),
            $config['bucket'],
            filled($config['storage_class'] ?? null) ? (string) $config['storage_class'] : null,
        );
    }

    public function put(string $localPath, string $key): void
    {
        $params = $this->storageClass !== null ? ['StorageClass' => $this->storageClass] : [];

        try {
            $size = (int) filesize($localPath);

            if ($size > self::MULTIPART_THRESHOLD) {
                (new MultipartUploader($this->client, $localPath, [
                    'bucket' => $this->bucket,
                    'key' => $key,
                    'params' => $params,
                    'part_size' => self::partSize($size),
                ]))->upload();

                return;
            }

            $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'SourceFile' => $localPath,
                ...$params,
            ]);
        } catch (Throwable $exception) {
            throw new BackupException("Upload to s3://{$this->bucket}/{$key} failed: {$exception->getMessage()}", previous: $exception);
        }
    }

    /**
     * S3 allows at most 10,000 parts per upload, of at least 5 MiB each.
     * 16 MiB parts cover dumps up to ~150 GiB; beyond that the parts grow so
     * the count stays under the limit, with headroom.
     */
    public static function partSize(int $bytes): int
    {
        return max(16 * 1024 * 1024, (int) ceil($bytes / 9000));
    }
}
