<?php

namespace Falak\Volumes\Domain\Models;

use Falak\Volumes\Contracts\AttachableType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $volume_id
 * @property AttachableType $attachable_type
 * @property string $attachable_id
 * @property ?string $service compose_service: the stack's service
 * @property string $mount_path an absolute path in the container; shared_path: the path relative to the release
 * @property bool $read_only
 * @property-read Volume $volume
 */
class Attachment extends Model
{
    use HasUlids;

    protected $table = 'volumes_attachments';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attachable_type' => AttachableType::class,
            'read_only' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Volume, $this>
     */
    public function volume(): BelongsTo
    {
        return $this->belongsTo(Volume::class);
    }

    /** The site behind it (sites and compose services), null for databases. */
    public function siteId(): ?string
    {
        return $this->attachable_type === AttachableType::Database ? null : $this->attachable_id;
    }
}
