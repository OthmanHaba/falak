<?php

namespace Falak\Edge\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $site_id
 * @property ?string $compose_service public service of a compose site it applies to (null: every route of the site)
 * @property string $name
 * @property string $value
 */
class Header extends Model
{
    use HasUlids;

    protected $table = 'edge_headers';

    /** @var list<string> */
    protected $guarded = [];
}
