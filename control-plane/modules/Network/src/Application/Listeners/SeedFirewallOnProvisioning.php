<?php

namespace Kiln\Network\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kiln\Network\Application\Actions\EnsureDefaultFirewallRules;
use Kiln\Network\Application\ApplyFirewall;
use Kiln\Servers\Contracts\ServerDirectory;
use Kiln\Servers\Events\ServerProvisioned;

/**
 * A freshly provisioned server gets its default rules (22, plus 80/443 when it serves HTTP) applied.
 */
final class SeedFirewallOnProvisioning implements ShouldQueue
{
    public function __construct(
        private readonly ServerDirectory $servers,
        private readonly EnsureDefaultFirewallRules $defaults,
        private readonly ApplyFirewall $apply,
    ) {}

    public function handle(ServerProvisioned $event): void
    {
        $server = $this->servers->find($event->serverId);

        if (! $server) {
            return;
        }

        ($this->defaults)($server);
        ($this->apply)($server->id);
    }
}
