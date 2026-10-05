<?php

namespace Falak\Edge\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * HTTP basic auth credential protecting a path (null path = the whole site).
 *
 * @property string $id
 * @property string $site_id
 * @property ?string $compose_service public service of a compose site it applies to (null: every route of the site)
 * @property ?string $name
 * @property ?string $path
 * @property string $username
 * @property string $password_hash bcrypt
 */
class SecurityRule extends Model
{
    use HasUlids;

    protected $table = 'edge_security_rules';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['password_hash'];
}
