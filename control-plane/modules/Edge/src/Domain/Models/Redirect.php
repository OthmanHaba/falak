<?php

namespace Kiln\Edge\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Path redirect rule (from is a Caddy path matcher, e.g. /old or /blog/*).
 *
 * @property string $id
 * @property string $site_id
 * @property ?string $compose_service public service of a compose site it applies to (null: every route of the site)
 * @property string $from
 * @property string $to
 * @property int $status
 * @property int $position
 */
class Redirect extends Model
{
    use HasUlids;

    public const STATUSES = [301, 302, 307, 308];

    protected $table = 'edge_redirects';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => 'integer', 'position' => 'integer'];
    }
}
