<?php

namespace Falak\Volumes\Domain\Models;

use Falak\Volumes\Domain\Enums\OperationKind;
use Falak\Volumes\Domain\Enums\OperationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $organization_id
 * @property ?string $volume_id
 * @property OperationKind $kind
 * @property OperationStatus $status
 * @property ?string $step
 * @property ?string $command_id
 * @property ?array<string, mixed> $meta
 * @property ?array<string, mixed> $result
 * @property ?string $error
 * @property ?string $requested_by
 * @property ?Carbon $finished_at
 * @property Carbon $created_at
 * @property-read ?Volume $volume
 */
class Operation extends Model
{
    use HasUlids;

    protected $table = 'volumes_operations';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => OperationKind::class,
            'status' => OperationStatus::class,
            'meta' => 'array',
            'result' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Volume, $this>
     */
    public function volume(): BelongsTo
    {
        return $this->belongsTo(Volume::class);
    }

    public function meta(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }

    public function succeed(?array $result = null): void
    {
        $this->forceFill(['status' => OperationStatus::Succeeded, 'result' => $result ?? $this->result, 'error' => null, 'finished_at' => now()])->save();
    }

    public function fail(string $error): void
    {
        $this->forceFill(['status' => OperationStatus::Failed, 'error' => mb_substr($error, 0, 1000), 'finished_at' => now()])->save();
    }
}
