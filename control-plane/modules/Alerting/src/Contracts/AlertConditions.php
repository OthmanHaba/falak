<?php

namespace Falak\Alerting\Contracts;

use Closure;
use Falak\Alerting\Contracts\Data\AlertData;

/**
 * Stateful conditions observed by periodic checks (a disk above 80%, a certificate close to expiry, a backup overdue):
 * the alert is raised once the condition has held for $forSeconds, and resolved once it no longer holds. Observations
 * in between raise nothing, so a check can run every minute without filling the alert history.
 *
 * $key identifies the condition within the organization and is the alert's dedup key; the recovery reuses it, so it
 * reaches the channels that got the alert. Conditions nobody observes for a week are forgotten (and their dedup key
 * released) without a recovery: the thing they were about is gone.
 *
 *     $conditions->observe($orgId, "servers.disk:{$serverId}:/", $percent > 90, fn () => new AlertData(...));
 */
interface AlertConditions
{
    /**
     * @param  Closure(): AlertData  $alert  built only when it is raised; its dedup key is replaced by $key
     * @param  (Closure(): AlertData)|null  $recovery  built when a raised condition clears (default: the alert's type and
     *                                                 title at Info, "no longer holds")
     * @param  bool|null  $holds  null: in the band between raising and resolving (hysteresis), nothing changes
     * @param  bool  $announceRecovery  false: a raised condition clears silently (a stage of a condition another key
     *                                  announces the end of, e.g. "expires in 7 days" after "expires in 14 days")
     */
    public function observe(string $organizationId, string $key, ?bool $holds, Closure $alert, ?Closure $recovery = null, int $forSeconds = 0, bool $announceRecovery = true): void;

    /**
     * Clears the organization's conditions under $prefix that are not in $keep (things that disappeared, e.g. an
     * unmounted disk), resolving the raised ones.
     *
     * @param  list<string>  $keep
     */
    public function clearExcept(string $organizationId, string $prefix, array $keep = []): void;
}
