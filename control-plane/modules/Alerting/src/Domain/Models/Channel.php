<?php

namespace Falak\Alerting\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Falak\Alerting\Domain\Enums\ChannelType;

/**
 * @property string $id
 * @property string $organization_id
 * @property ChannelType $type
 * @property string $name
 * @property array<string, mixed> $config secrets: webhook URLs, bot tokens, signing secrets
 * @property bool $enabled
 * @property ?Carbon $last_sent_at
 * @property ?string $last_error
 * @property Carbon $created_at
 */
class Channel extends Model
{
    use HasUlids;

    protected $table = 'alerting_channels';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['config'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ChannelType::class,
            'config' => 'encrypted:array',
            'enabled' => 'boolean',
            'last_sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Rule, $this>
     */
    public function rules(): BelongsToMany
    {
        return $this->belongsToMany(Rule::class, 'alerting_rule_channels', 'channel_id', 'rule_id');
    }
}
