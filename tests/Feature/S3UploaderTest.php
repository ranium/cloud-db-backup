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
