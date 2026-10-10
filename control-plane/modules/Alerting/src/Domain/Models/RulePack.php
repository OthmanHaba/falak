<?php

namespace Falak\Alerting\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One area of the default rule pack applied to an organization (DefaultRulePack). Kept when the rule is deleted, so
 * the pack never re-creates it.
 *
 * @property int $id
 * @property string $organization_id
 * @property string $pack_key "area:<group slug>"
 * @property ?string $rule_id
 * @property list<string> $patterns event type patterns applied so far
 * @property Carbon $applied_at
 */
class RulePack extends Model
{
    public $timestamps = false;

    protected $table = 'alerting_rule_packs';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['patterns' => 'array', 'applied_at' => 'datetime'];
    }
}
