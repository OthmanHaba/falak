<?php

namespace Falak\Edge\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A DNS record Falak created for one preview host on a server other than the preview edge server.
 *
 * @property string $id
 * @property string $organization_id the preview site's
 * @property ?string $site_id null: a former wildcard record
 * @property ?string $dns_credential_id the credential that created it (deleted with it)
 * @property bool $deleting a tombstone, retried until Cloudflare deleted the record
 * @property int $attempts
 * @property ?string $error
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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['deleting' => 'boolean', 'attempts' => 'integer'];
    }
}
