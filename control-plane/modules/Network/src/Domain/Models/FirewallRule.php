<?php

namespace Falak\Network\Domain\Models;

use Falak\Network\Domain\Enums\RuleAction;
use Falak\Network\Domain\Enums\RuleProtocol;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $server_id
 * @property string $name
 * @property RuleAction $action
 * @property RuleProtocol $protocol
 * @property ?string $port "22" or "8000-8100"; null = all ports
 * @property ?string $source address or CIDR; null = anywhere
 * @property int $position
 * @property bool $is_default
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class FirewallRule extends Model
{
    use HasUlids;

    protected $table = 'network_firewall_rules';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => RuleAction::class,
            'protocol' => RuleProtocol::class,
            'position' => 'integer',
            'is_default' => 'boolean',
        ];
    }
}
