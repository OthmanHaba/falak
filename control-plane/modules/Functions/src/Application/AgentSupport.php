<?php

namespace Falak\Functions\Application;

use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Sites\Contracts\Data\SiteData;

/**
 * What the agents on a function's servers can run. Code with more than one file needs agent feature fn.v3 (it
 * validates file trees before writing them); older agents get a clear "update the agent" instead.
 */
final class AgentSupport
{
    public function __construct(private readonly AgentDirectory $agents) {}

    /**
     * Why $files cannot be deployed to the site's servers now, or null.
     *
     * @param  array<string, string>  $files
     */
    public function blocker(SiteData $site, array $files): ?string
    {
        if (count($files) <= 1) {
            return null;
        }

        // A server without an (enrolled, unrevoked) agent can't confirm the feature either, as for SiteRules' fn.v1.
        $agents = $this->agents->forServers($site->serverIds());
        foreach ($site->serverIds() as $serverId) {
            $agent = $agents[$serverId] ?? null;
            if ($agent === null) {
                return 'the function’s server has no connected Falak agent, which functions with several files need; enroll it first.';
            }
            if (! $agent->supports('fn.v3')) {
                return 'the Falak agent on the function’s server is too old for functions with several files; update it first.';
            }
        }

        return null;
    }
}
