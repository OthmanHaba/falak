<?php

namespace Falak\Sites\Domain\Models;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Falak\Sites\Contracts\Data\ComposeVersionData;

/**
 * An immutable version of an inline compose file (encrypted at rest: pasted stacks often carry secrets).
 *
 * @property string $id
 * @property string $site_id
 * @property int $version
 * @property string $content
 * @property ?string $created_by
 * @property Carbon $created_at
 */
class ComposeVersion extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'sites_compose_versions';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['content'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['version' => 'integer', 'content' => 'encrypted'];
    }

    public function toData(): ComposeVersionData
    {
        return new ComposeVersionData($this->site_id, $this->version, $this->content, $this->created_by, DateTimeImmutable::createFromInterface($this->created_at));
    }
}
