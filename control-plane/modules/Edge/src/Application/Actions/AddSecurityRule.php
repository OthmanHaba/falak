<?php

namespace Kiln\Edge\Application\Actions;

use Illuminate\Support\Facades\Hash;
use Kiln\Edge\Application\EdgeChanges;
use Kiln\Edge\Domain\Models\SecurityRule;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Sites\Contracts\Data\SiteData;
use SensitiveParameter;

final class AddSecurityRule
{
    public function __construct(
        private readonly EdgeChanges $changes,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  ?string  $path  Caddy path matcher (e.g. /admin/*); null protects the whole site
     */
    public function __invoke(SiteData $site, ?string $name, ?string $path, string $username, #[SensitiveParameter] string $password): SecurityRule
    {
        $rule = SecurityRule::query()->create([
            'site_id' => $site->id,
            'name' => $name,
            'path' => $path,
            'username' => $username,
            // Caddy's http_basic verifies bcrypt hashes.
            'password_hash' => Hash::driver('bcrypt')->make($password, ['rounds' => max(10, (int) config('hashing.bcrypt.rounds', 12))]),
        ]);

        $this->audit->record('edge.security_rule_added', 'site', $site->id, ['path' => $path ?? '/*', 'username' => $username], $site->organizationId);
        $this->changes->siteChanged($site->id);

        return $rule;
    }
}
