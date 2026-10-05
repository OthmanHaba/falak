<?php

namespace Falak\Templates\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $template_id
 * @property int $revision
 * @property string $version
 * @property string $template_yaml
 * @property string $compose_yaml
 * @property ?string $created_by
 * @property Carbon $created_at
 */
class CustomTemplateRevision extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'templates_custom_revisions';

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['revision' => 'integer'];
    }
}
