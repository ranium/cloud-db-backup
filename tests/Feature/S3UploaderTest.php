<?php

use App\Backup\BackupException;
use App\Backup\S3Uploader;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;

function mockS3(MockHandler $handler): S3Client
{
    return new S3Client([
        'version' => 'latest',
        'region' => 'ap-south-1',
        'credentials' => ['key' => 'k', 'secret' => 's'],
        'handler' => $handler,
    ]);
}

it('uploads to Standard-IA without sending an ACL', function () {
    $handler = new MockHandler;
    $sent = [];
    $handler->append(function (CommandInterface $command) use (&$sent) {
        $sent[] = $command;

        return new Result([]);
    });
    $file = tempnam(sys_get_temp_dir(), 'up');
    file_put_contents($file, 'encrypted');

    (new S3Uploader(mockS3($handler), 'ranium-offsite-backups'))->put($file, 'db/daily/main/app/x.sql.gz.age');

    expect($sent)->toHaveCount(1)
        ->and($sent[0]->getName())->toBe('PutObject')
        ->and($sent[0]['Bucket'])->toBe('ranium-offsite-backups')
        ->and($sent[0]['Key'])->toBe('db/daily/main/app/x.sql.gz.age')
        ->and($sent[0]['StorageClass'])->toBe('STANDARD_IA')
        ->and($sent[0]->hasParam('ACL'))->toBeFalse();
});

it('sends no storage class when none is configured', function () {
    $handler = new MockHandler;
    $sent = [];
    $handler->append(function (CommandInterface $command) use (&$sent) {
        $sent[] = $command;

        return new Result([]);
    });
    $file = tempnam(sys_get_temp_dir(), 'up');

    (new S3Uploader(mockS3($handler), 'spaces-bucket', null))->put($file, 'db/x');

    expect($sent[0]->hasParam('StorageClass'))->toBeFalse();
});

it('talks to an S3-compatible endpoint when one is configured', function () {
    config(['backup.s3' => [
        'bucket' => 'client-backups', 'region' => 'blr1', 'key' => 'k', 'secret' => 's', 'prefix' => 'db',
        'endpoint' => 'https://blr1.digitaloceanspaces.com', 'use_path_style' => false, 'storage_class' => '',
    ]]);

    $uploader = S3Uploader::fromConfig();
    $client = (fn () => $this->client)->call($uploader);
    $storageClass = (fn () => $this->storageClass)->call($uploader);

    expect((string) $client->getEndpoint())->toBe('https://blr1.digitaloceanspaces.com')
        ->and($storageClass)->toBeNull();
});

it('defaults to AWS and Standard-IA', function () {
    config(['backup.s3' => ['bucket' => 'b', 'region' => 'ap-south-1', 'key' => 'k', 'secret' => 's', 'prefix' => 'db', 'storage_class' => 'STANDARD_IA']]);

    $uploader = S3Uploader::fromConfig();

    expect((string) (fn () => $this->client)->call($uploader)->getEndpoint())->toBe('https://s3.ap-south-1.amazonaws.com')
        ->and((fn () => $this->storageClass)->call($uploader))->toBe('STANDARD_IA');
});

it('turns an S3 error into a backup failure', function () {
    $handler = new MockHandler;
    $handler->append(fn (CommandInterface $command) => new S3Exception('Access Denied', $command));
    $file = tempnam(sys_get_temp_dir(), 'up');

    (new S3Uploader(mockS3($handler), 'bucket'))->put($file, 'db/x');
})->throws(BackupException::class, 'Upload to s3://bucket/db/x failed: Access Denied');

it('names the missing setting', function () {
    config(['backup.s3' => ['bucket' => 'b', 'region' => 'ap-south-1', 'key' => null, 'secret' => 's', 'prefix' => 'db']]);

    S3Uploader::fromConfig();
})->throws(BackupException::class, 'BACKUP_S3_KEY is not set.');

it('keeps a multipart upload under the 10,000-part limit at any size', function (int $bytes) {
    $part = S3Uploader::partSize($bytes);

    expect($part)->toBeGreaterThanOrEqual(5 * 1024 * 1024)
        ->and((int) ceil($bytes / $part))->toBeLessThanOrEqual(10000);
})->with([
    '65 MB' => 65 * 1024 * 1024,
    '50 GB' => 50 * 1024 ** 3,
    '400 GB' => 400 * 1024 ** 3,
]);
