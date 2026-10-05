<?php

namespace Falak\Sites\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Last reported state of a site's compose project on one server (docker.compose.ps / up results).
 *
 * @property string $id
 * @property string $site_id
 * @property string $server_id
 * @property list<array<string, mixed>> $services
 * @property ?string $command_id pending docker.compose.ps
 * @property ?Carbon $requested_at
 * @property ?Carbon $reported_at
 */
class ComposeState extends Model
{
    use HasUlids;

    protected $table = 'sites_compose_states';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['services' => 'array', 'requested_at' => 'datetime', 'reported_at' => 'datetime'];
    }
}
