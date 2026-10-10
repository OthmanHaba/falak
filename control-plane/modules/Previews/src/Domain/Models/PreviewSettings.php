<?php

namespace Falak\Previews\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A project's preview settings.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property bool $enabled
 * @property ?string $base_environment_id
 * @property ?array<string, string> $services service name => include | share | omit (unlisted: include)
 * @property ?string $server_id
 * @property string $domain_pattern
 * @property ?array<string, array{strategy: string, source_environment_id?: ?string, sanitize_kind?: ?string, sanitize_script?: ?string}> $databases
 * @property int $max_concurrent
 * @property int $idle_ttl_hours
 * @property string $access basic | public
 * @property ?string $updated_by
 */
class PreviewSettings extends Model
{
    use HasUlids;

    public const INCLUDE = 'include';

    public const SHARE = 'share';

    public const OMIT = 'omit';

    public const EMPTY = 'empty';

    public const CLONE_BACKUP = 'clone_backup';

    public const CLONE_SANITIZE = 'clone_sanitize';

    protected $table = 'previews_settings';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = ['domain_pattern' => 'pr-{number}-{service}', 'max_concurrent' => 5, 'idle_ttl_hours' => 72, 'access' => 'basic', 'enabled' => false];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'services' => 'array',
            'databases' => 'array',
            'max_concurrent' => 'integer',
            'idle_ttl_hours' => 'integer',
        ];
    }

    public function modeOf(string $service): string
    {
        return ($this->services ?? [])[$service] ?? self::INCLUDE;
    }

    /**
     * @return array{strategy: string, source_environment_id?: ?string, sanitize_kind?: ?string, sanitize_script?: ?string}
     */
    public function databaseOf(string $service): array
    {
        return ($this->databases ?? [])[$service] ?? ['strategy' => self::EMPTY];
    }
}
