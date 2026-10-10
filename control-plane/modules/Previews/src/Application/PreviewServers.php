<?php

namespace Falak\Previews\Application;

use Falak\Databases\Contracts\DatabaseDirectory;
use Falak\Edge\Contracts\PreviewDomains;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteRuntime;

/**
 * Where a fork's pull request may run. Its code is untrusted: only on a server that hosts nothing but previews (no
 * site or database outside a preview environment, whatever project), never on the preview edge server that holds
 * the DNS token of the wildcard certificate, and only as containers (Docker / Compose: no native PHP or Node next to
 * the host's files).
 */
final class PreviewServers
{
    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly DatabaseDirectory $databases,
        private readonly ProjectDirectory $projects,
        private readonly PreviewDomains $domains,
    ) {}

    /** Why the server can't run a fork's preview (null: it can). */
    public function forkProblem(string $serverId): ?string
    {
        $domain = $this->domains->settings();

        if ($domain !== null && $domain->managedDns && $domain->serverId === strtolower($serverId)) {
            return 'Pull requests from forks never run on the preview edge server (it holds the DNS token of the wildcard certificate). Pick another fork server.';
        }

        foreach ($this->sites->forServer(strtolower($serverId)) as $site) {
            if (! $this->inPreview(ServiceKind::Site, $site->id)) {
                return "Pull requests from forks run only on a server that hosts nothing but previews, and {$site->name} runs there. Pick a dedicated fork server.";
            }
        }

        foreach ($this->databases->forServer(strtolower($serverId)) as $database) {
            if (! $this->inPreview(ServiceKind::Database, $database->id)) {
                return "Pull requests from forks run only on a server that hosts nothing but previews, and the database {$database->name} runs there. Pick a dedicated fork server.";
            }
        }

        return null;
    }

    /** Forks run as containers only. */
    public static function forkRuntime(SiteRuntime $runtime): bool
    {
        return $runtime === SiteRuntime::Docker || $runtime === SiteRuntime::Compose;
    }

    private function inPreview(ServiceKind $kind, string $id): bool
    {
        $placed = $this->projects->projectOf($kind, $id);

        return $placed !== null && ($this->projects->environment($placed->environmentId)?->isPreview ?? false);
    }
}
