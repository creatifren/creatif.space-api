<?php

namespace App\Support\Media;

use Aws\S3\S3Client;
use Aws\Signature\SignatureProvider;
use DateTimeInterface;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;

/**
 * A presigned PUT locked to one Content-Type and one Content-Length
 * (LockedPutSignature). Disks that are not S3/R2 (tests' fakes) fall back to
 * the disk's own temporaryUploadUrl.
 */
final class PresignedUpload
{
    public static function url(string $disk, string $path, string $mime, int $bytes, DateTimeInterface $expires): string
    {
        $filesystem = Storage::disk($disk);

        if (! $filesystem instanceof AwsS3V3Adapter) {
            return $filesystem->temporaryUploadUrl($path, $expires, ['ContentType' => $mime, 'ContentLength' => $bytes])['url'];
        }

        $config = $filesystem->getConfig();
        $base = $filesystem->getClient();
        $prefix = trim((string) ($config['root'] ?? ''), '/');

        $client = new S3Client([
            'version' => 'latest',
            'region' => $base->getRegion(),
            'endpoint' => isset($config['endpoint']) ? (string) $config['endpoint'] : null,
            'use_path_style_endpoint' => (bool) ($config['use_path_style_endpoint'] ?? false),
            'credentials' => fn () => $base->getCredentials(),
            'signature_provider' => function (string $version, string $service, string $region) {
                return in_array($version, ['v4', 's3v4'], true) && $service === 's3'
                    ? new LockedPutSignature($service, $region)
                    : SignatureProvider::defaultProvider()($version, $service, $region);
            },
        ]);

        $command = $client->getCommand('PutObject', [
            'Bucket' => $config['bucket'],
            'Key' => ($prefix === '' ? '' : $prefix.'/').$path,
            'ContentType' => $mime,
            'ContentLength' => $bytes,
        ]);

        return (string) $client->createPresignedRequest($command, $expires)->getUri();
    }
}
