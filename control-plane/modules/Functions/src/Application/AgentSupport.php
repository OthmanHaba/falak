<?php

namespace Kiln\Functions\Application;

use Kiln\Fleet\Contracts\AgentDirectory;
use Kiln\Sites\Contracts\Data\SiteData;

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

        foreach ($this->agents->forServers($site->serverIds()) as $agent) {
            if (! $agent->supports('fn.v3')) {
                return 'the Kiln agent on the function’s server is too old for functions with several files; update it first.';
            }
        }

        return null;
    }
}
