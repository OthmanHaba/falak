<?php

namespace Kiln\Edge\Contracts;

use Kiln\Edge\Contracts\Data\DnsCheckResult;

/**
 * Live DNS check for a domain the user wants to route (resolved from the control plane with a short timeout).
 */
interface DnsCheck
{
    /**
     * @param  list<string>  $serverIds  the servers that will serve the name (leader first); ignored when $siteId sits
     *                                   behind a load balancer (its IP is expected instead)
     * @param  ?string  $label  the site / service label; its generated name is offered as a CNAME target
     * @param  bool  $probeTls  also report the certificate served for the name (existing sites, after a deploy)
     */
    public function check(string $organizationId, string $name, array $serverIds, ?string $siteId = null, ?string $label = null, bool $probeTls = false): DnsCheckResult;
}
