<?php

namespace Falak\Edge\Application\Actions;

use Falak\Edge\Application\EdgeChanges;
use Falak\Edge\Domain\Models\Header;
use Falak\Edge\Domain\Models\Redirect;
use Falak\Edge\Domain\Models\SecurityRule;
use Falak\Identity\Contracts\AuditLog;

/**
 * Deletes a redirect, security rule or header of a site.
 */
final class DeleteSiteRule
{
    public function __construct(
        private readonly EdgeChanges $changes,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(Redirect|SecurityRule|Header $rule, string $organizationId): void
    {
        $rule->delete();

        [$action, $context] = match (true) {
            $rule instanceof Redirect => ['edge.redirect_removed', ['from' => $rule->from]],
            $rule instanceof SecurityRule => ['edge.security_rule_removed', ['path' => $rule->path ?? '/*', 'username' => $rule->username]],
            default => ['edge.header_removed', ['name' => $rule->name]],
        };

        $this->audit->record($action, 'site', $rule->site_id, $context, $organizationId);
        $this->changes->siteChanged($rule->site_id);
    }
}
