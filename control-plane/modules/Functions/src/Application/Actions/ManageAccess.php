<?php

namespace Falak\Functions\Application\Actions;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Falak\Deployments\Contracts\DeploymentDirectory;
use Falak\Deployments\Contracts\DeploymentTrigger;
use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Functions\Domain\Models\CloudFunction;
use Falak\Functions\Domain\Models\FunctionApiKey;
use Falak\Identity\Contracts\AuditLog;
use Falak\Sites\Contracts\Data\SiteData;

/**
 * Settings → Access: API keys and the IP allowlist of a function. The gateway enforces them; a change redeploys the
 * live version (same code) so the servers get it.
 */
final class ManageAccess
{
    public const MAX_KEYS = 20;

    public const MAX_CIDRS = 50;

    public function __construct(
        private readonly DeploymentDirectory $directory,
        private readonly DeploymentTrigger $deployments,
        private readonly AgentDirectory $agents,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @return array{key: string, model: FunctionApiKey, deployment_id: ?string}
     *
     * @throws ValidationException
     */
    public function createKey(SiteData $site, CloudFunction $function, string $name, ?string $userId): array
    {
        $this->requireGateway($site);

        if (FunctionApiKey::query()->where('function_id', $function->id)->count() >= self::MAX_KEYS) {
            throw ValidationException::withMessages(['name' => 'A function can have at most '.self::MAX_KEYS.' keys.']);
        }

        $key = 'kfn_'.Str::random(40);
        $model = FunctionApiKey::query()->create([
            'function_id' => $function->id,
            'name' => trim($name),
            'hash' => hash('sha256', $key),
            'prefix' => substr($key, 0, 12),
            'created_by' => $userId,
        ]);
        $this->audit->record('function.api_key.created', 'site', $site->id, ['name' => $model->name, 'prefix' => $model->prefix], $site->organizationId);

        return ['key' => $key, 'model' => $model, 'deployment_id' => $this->redeploy($site, $userId)];
    }

    public function revokeKey(SiteData $site, FunctionApiKey $key, ?string $userId): ?string
    {
        $key->delete();
        $this->audit->record('function.api_key.revoked', 'site', $site->id, ['name' => $key->name, 'prefix' => $key->prefix], $site->organizationId);

        return $this->redeploy($site, $userId);
    }

    /**
     * @param  list<string>  $cidrs  IPs or CIDRs; empty = anyone
     *
     * @throws ValidationException
     */
    public function setAllowlist(SiteData $site, CloudFunction $function, array $cidrs, ?string $userId): ?string
    {
        $normalized = [];

        foreach ($cidrs as $entry) {
            $entry = trim((string) $entry);

            if ($entry === '') {
                continue;
            }

            $normalized[] = self::cidr($entry) ?? throw ValidationException::withMessages(['allow_cidrs' => "“{$entry}” is not an IP address or CIDR range."]);
        }

        $normalized = array_values(array_unique($normalized));

        if (count($normalized) > self::MAX_CIDRS) {
            throw ValidationException::withMessages(['allow_cidrs' => 'At most '.self::MAX_CIDRS.' entries.']);
        }

        if ($normalized !== []) {
            $this->requireGateway($site);
        }

        if ($normalized === array_values((array) ($function->allow_cidrs ?? []))) {
            return null;
        }

        $function->forceFill(['allow_cidrs' => $normalized === [] ? null : $normalized])->save();
        $this->audit->record('function.allowlist.updated', 'site', $site->id, ['allow_cidrs' => $normalized], $site->organizationId);

        return $this->redeploy($site, $userId);
    }

    /** "203.0.113.7" → "203.0.113.7/32", "2001:db8::/32" stays; null when invalid. */
    public static function cidr(string $entry): ?string
    {
        [$ip, $bits] = str_contains($entry, '/') ? explode('/', $entry, 2) : [$entry, null];
        $v6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

        if (! $v6 && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        $max = $v6 ? 128 : 32;

        if ($bits !== null && (! ctype_digit($bits) || (int) $bits > $max)) {
            return null;
        }

        return strtolower($ip).'/'.($bits ?? $max);
    }

    /**
     * Restrictions only work where the gateway enforces them (agent feature fn.v2).
     *
     * @throws ValidationException
     */
    private function requireGateway(SiteData $site): void
    {
        foreach ($site->serverIds() as $serverId) {
            if (! ($this->agents->forServer($serverId)?->supports('fn.v2') ?? false)) {
                throw ValidationException::withMessages(['access' => 'Update the Falak agent on the function’s server first: it is too old to enforce access rules.']);
            }
        }
    }

    /**
     * Rules not applied yet when no deployment could start (e.g. the server is not ready) are reported, not hidden:
     * the change is saved, and the next deployment applies it.
     *
     * @throws ValidationException
     */
    private function redeploy(SiteData $site, ?string $userId): ?string
    {
        $live = $this->directory->liveCommit($site->id);

        if ($live === null) {
            return null;
        }

        try {
            return $this->deployments->deploy($site->id, $userId, $live, 'Apply access settings');
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['access' => 'Saved, but not applied yet: '.collect($e->errors())->flatten()->first().' It applies with the next deployment.']);
        }
    }
}
