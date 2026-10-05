<?php

namespace Falak\SourceControl\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Log of verified push webhooks.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $connection_id
 * @property ?string $webhook_id
 * @property string $repository
 * @property string $branch
 * @property string $sha
 * @property ?string $before_sha
 * @property ?string $author_name
 * @property ?string $author_email
 * @property string $message
 * @property ?string $url
 * @property ?string $pusher
 * @property ?Carbon $committed_at
 * @property Carbon $received_at
 */
class Push extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'source_control_pushes';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'committed_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }
}
