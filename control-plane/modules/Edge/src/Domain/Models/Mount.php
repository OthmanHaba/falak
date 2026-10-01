<?php

namespace Kiln\Edge\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A path of a site served by a function (`<site domains><path_prefix>/*` → the function).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $site_id
 * @property string $function_site_id
 * @property string $path_prefix
 * @property bool $strip_prefix
 */
class Mount extends Model
{
    use HasUlids;

    public const PATH_PATTERN = '/^\/(?!.*\.\.)[A-Za-z0-9._~!$&\'()*+,;=:@%\/-]{0,198}[A-Za-z0-9._~!$&\'()*+,;=:@%-]$/';

    protected $table = 'edge_mounts';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['strip_prefix' => 'boolean'];
    }
}
