<?php

namespace Kiln\Network\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Membership or convergence of a private network changed.
 */
final class PrivateNetworkChanged
{
    use Dispatchable;

    public const MEMBER_ADDED = 'member_added';

    public const MEMBER_REMOVED = 'member_removed';

    public const DELETED = 'deleted';

    public const APPLIED = 'applied';

    /**
     * @param  string  $change  one of the constants above
     * @param  list<string>  $serverIds  servers affected by the change
     */
    public function __construct(
        public string $networkId,
        public string $organizationId,
        public string $change,
        public array $serverIds,
    ) {}
}
