<?php

namespace Falak\Edge\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A DNS record Falak created for one preview host on a server other than the preview edge server.
 *
 * @property string $id
 * @property string $organization_id the preview site's
 * @property string $site_id
 * @property string $host
 * @property string $zone_id
 * @property ?string $record_id
 * @property string $content
 */
class PreviewRecord extends Model
{
    use HasUlids;

    protected $table = 'edge_preview_records';

    /** @var list<string> */
    protected $guarded = [];
}
