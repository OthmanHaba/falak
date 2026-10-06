<?php

namespace Falak\Deployments\Domain\Models;

use Falak\Deployments\Domain\Enums\Strategy;
use Falak\Kernel\Security\Casts\Sealed;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteRuntime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Per-site deployment settings (created with defaults on first use).
 *
 * @property string $site_id
 * @property string $organization_id
 * @property ?Strategy $strategy
 * @property int $batch_size
 * @property int $keep_releases
 * @property bool $health_enabled
 * @property ?string $health_path
 * @property int $health_status
 * @property int $health_timeout_s
 * @property int $health_retries
 * @property int $health_retry_delay_s
 * @property string $secrets_mode env | files (container sites: secret variables as /run/secrets files)
 * @property ?string $hook_token_hash
 * @property ?string $hook_token
 */
class SiteSettings extends Model
{
    public const SECRETS_ENV = 'env';

    public const SECRETS_FILES = 'files';

    protected $table = 'deployments_site_settings';

    protected $primaryKey = 'site_id';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['hook_token', 'hook_token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'strategy' => Strategy::class,
            'batch_size' => 'integer',
            'keep_releases' => 'integer',
            'health_enabled' => 'boolean',
            'health_status' => 'integer',
            'health_timeout_s' => 'integer',
            'health_retries' => 'integer',
            'health_retry_delay_s' => 'integer',
            'hook_token' => Sealed::class,
        ];
    }

    public static function for(SiteData $site): self
    {
        $defaults = (array) config('deployments.defaults');

        /** @var self */
        return self::query()->firstOrCreate(['site_id' => $site->id], [
            'organization_id' => $site->organizationId,
            'batch_size' => (int) ($defaults['batch_size'] ?? 1),
            'keep_releases' => (int) ($defaults['keep_releases'] ?? 5),
            'health_enabled' => (bool) ($defaults['health']['enabled'] ?? true),
            'health_status' => (int) ($defaults['health']['status'] ?? 200),
            'health_timeout_s' => (int) ($defaults['health']['timeout_s'] ?? 10),
            'health_retries' => (int) ($defaults['health']['retries'] ?? 3),
            'health_retry_delay_s' => (int) ($defaults['health']['retry_delay_s'] ?? 5),
            'secrets_mode' => self::SECRETS_ENV,
        ]);
    }

    public function effectiveStrategy(SiteData $site): Strategy
    {
        $allowed = Strategy::for($site->runtime);

        return $this->strategy !== null && in_array($this->strategy, $allowed, true) ? $this->strategy : Strategy::default($site->runtime);
    }

    /**
     * Files only reach containers (docker sites); every other runtime keeps environment variables.
     */
    public function effectiveSecretsMode(SiteData $site): string
    {
        return $site->runtime === SiteRuntime::Docker && $this->secrets_mode === self::SECRETS_FILES ? self::SECRETS_FILES : self::SECRETS_ENV;
    }

    public function healthPath(SiteData $site): string
    {
        $path = $this->health_path ?: $site->healthCheckPath ?: '/';

        return str_starts_with($path, '/') ? $path : '/'.$path;
    }

    /**
     * Rotate the deploy hook token. Returns the plain token.
     */
    public function rotateHookToken(): string
    {
        $token = Str::random(48);
        $this->forceFill(['hook_token' => $token, 'hook_token_hash' => hash('sha256', $token)])->save();

        return $token;
    }

    /**
     * Snapshot stored on each deployment so a running deployment is unaffected by later edits.
     *
     * @return array<string, mixed>
     */
    public function snapshot(SiteData $site): array
    {
        return [
            'strategy' => $this->effectiveStrategy($site)->value,
            'batch_size' => max(1, $this->batch_size),
            'keep_releases' => max(1, $this->keep_releases),
            'health' => [
                'enabled' => $this->health_enabled,
                'path' => $this->healthPath($site),
                'status' => $this->health_status,
                'timeout_s' => max(1, $this->health_timeout_s),
                'retries' => max(1, $this->health_retries),
                'retry_delay_s' => max(0, $this->health_retry_delay_s),
            ],
            'secrets_mode' => $this->effectiveSecretsMode($site),
        ];
    }
}
