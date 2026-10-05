<?php

namespace Falak\Databases\Domain\Models;

use Falak\Databases\Domain\Enums\StorageDriver;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * S3-compatible bucket for backups. Credentials are encrypted and never leave the control plane:
 * servers only ever receive presigned URLs.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property StorageDriver $driver
 * @property ?string $endpoint https base URL (derived for S3/R2/B2/Spaces, required for MinIO)
 * @property string $region
 * @property string $bucket
 * @property ?string $prefix
 * @property bool $path_style
 * @property string $access_key_id
 * @property string $secret_access_key
 * @property ?Carbon $verified_at
 * @property ?string $created_by
 * @property Carbon $created_at
 */
class StorageProvider extends Model
{
    use HasUlids;

    protected $table = 'databases_storage_providers';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['access_key_id', 'secret_access_key'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'driver' => StorageDriver::class,
            'path_style' => 'boolean',
            'access_key_id' => 'encrypted',
            'secret_access_key' => 'encrypted',
            'verified_at' => 'datetime',
        ];
    }
}
