<?php

namespace Kiln\Builds\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A kiln-builder worker authenticated with a bearer token (only its SHA-256 is stored).
 *
 * @property string $id
 * @property ?string $organization_id
 * @property string $name
 * @property string $kind local|server|external
 * @property ?string $server_id
 * @property string $token_hash
 * @property list<string> $modes
 * @property bool $enabled
 * @property ?string $reported_name
 * @property ?string $last_ip
 * @property ?Carbon $last_seen_at
 * @property ?string $install_command_id
 * @property Carbon $created_at
 */
class Builder extends Model
{
    use HasUlids;

    public const KIND_LOCAL = 'local';

    public const KIND_SERVER = 'server';

    public const KIND_EXTERNAL = 'external';

    protected $table = 'builds_builders';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'modes' => 'array',
            'enabled' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function newToken(): string
    {
        return 'kbt_'.Str::random(48);
    }

    public function supports(string $mode): bool
    {
        return in_array($mode, $this->modes, true);
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->greaterThan(now()->subSeconds((int) config('builds.builder_online_seconds', 120)));
    }
}
