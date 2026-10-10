<?php

namespace Falak\Sites\Infrastructure;

use Falak\Sites\Application\Actions\SetSiteTargets;
use Falak\Sites\Contracts\SiteRelocation;
use Falak\Sites\Domain\Models\Site;
use Illuminate\Validation\ValidationException;

final class ActionSiteRelocation implements SiteRelocation
{
    public function __construct(private readonly SetSiteTargets $targets) {}

    public function replaceServer(string $siteId, string $fromServerId, string $toServerId): void
    {
        $site = Site::query()->with('targets')->find($siteId) ?? throw ValidationException::withMessages(['site' => 'Unknown site.']);
        $current = $site->serverIds();

        if (! in_array($fromServerId, $current, true)) {
            return; // Already moved (a retried step).
        }

        $servers = array_values(array_unique(array_map(fn (string $id) => $id === $fromServerId ? $toServerId : $id, $current)));
        $leader = $site->leaderTarget()?->server_id;
        $leader = $leader === null || $leader === $fromServerId ? $toServerId : $leader;

        ($this->targets)($site, $servers, $leader);
    }
}
