<?php

namespace Kiln\Builds\Application\Artifacts;

/**
 * Where native release tarballs live. Builders upload with a presigned PUT; agents download with a
 * presigned GET (deploy.fetch). Drivers: `local` (served by this control plane through signed,
 * expiring URLs) and `s3` (any S3-compatible store, SigV4 presigned URLs).
 */
interface ArtifactStorage
{
    public function driver(): string;

    /**
     * @return array{url: string, headers: array<string, string>}
     */
    public function uploadTarget(string $key, int $ttlSeconds): array;

    /** Presigned https GET URL. */
    public function downloadUrl(string $key, int $ttlSeconds): string;

    /** SHA-256 of the stored object when the driver can compute it cheaply (local), else null. */
    public function checksum(string $key): ?string;

    public function size(string $key): ?int;

    public function delete(string $key): void;
}
