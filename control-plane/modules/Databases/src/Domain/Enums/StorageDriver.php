<?php

namespace Kiln\Databases\Domain\Enums;

/**
 * S3-compatible object stores backups can be shipped to.
 */
enum StorageDriver: string
{
    case S3 = 's3';
    case R2 = 'r2';
    case B2 = 'b2';
    case Spaces = 'spaces';
    case Minio = 'minio';

    public function label(): string
    {
        return match ($this) {
            self::S3 => 'Amazon S3',
            self::R2 => 'Cloudflare R2',
            self::B2 => 'Backblaze B2',
            self::Spaces => 'DigitalOcean Spaces',
            self::Minio => 'MinIO / S3-compatible',
        };
    }

    /** Whether the user must supply the endpoint (otherwise it is derived from region / account). */
    public function requiresEndpoint(): bool
    {
        return $this === self::Minio;
    }

    public function defaultRegion(): ?string
    {
        return match ($this) {
            self::R2 => 'auto',
            self::Minio => 'us-east-1',
            default => null,
        };
    }

    public function regionHint(): string
    {
        return match ($this) {
            self::S3 => 'e.g. eu-central-1',
            self::R2 => 'auto',
            self::B2 => 'e.g. us-west-004',
            self::Spaces => 'e.g. fra1',
            self::Minio => 'us-east-1 unless configured otherwise',
        };
    }
}
