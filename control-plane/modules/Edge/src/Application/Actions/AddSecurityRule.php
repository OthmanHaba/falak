<?php

namespace Falak\Edge\Application\Actions;

use Falak\Edge\Application\EdgeChanges;
use Falak\Edge\Domain\Models\SecurityRule;
use Falak\Identity\Contracts\AuditLog;
use Falak\Sites\Contracts\Data\SiteData;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

final class AddSecurityRule
{
    public function __construct(
        private readonly EdgeChanges $changes,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  ?string  $path  Caddy path matcher (e.g. /admin/*); null protects the whole site
     * @param  ?string  $service  public service of a compose site (null: every route of the site)
     */
    public function __invoke(SiteData $site, ?string $name, ?string $path, string $username, #[SensitiveParameter] string $password, ?string $service = null): SecurityRule
    {
        $rule = SecurityRule::query()->create([
            'site_id' => $site->id,
            'compose_service' => $service,
            'name' => $name,
            'path' => $path,
            'username' => $username,
            // Caddy's http_basic verifies bcrypt hashes.
            'password_hash' => Hash::driver('bcrypt')->make($password, ['rounds' => max(10, (int) config('hashing.bcrypt.rounds', 12))]),
        ]);

        $this->audit->record('edge.security_rule_added', 'site', $site->id, array_filter(['path' => $path ?? '/*', 'username' => $username, 'service' => $service]), $site->organizationId);
        $this->changes->siteChanged($site->id);

        return $rule;
    }
}
