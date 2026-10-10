<?php

namespace Falak\Kernel\Http;

use Illuminate\Auth\SessionGuard;

/**
 * The session guard with a `__Host-` prefixed remember-me cookie (see HostCookies): a preview on a sibling host
 * can't plant one for the shared registrable domain. Registered as the `falak-session` auth driver.
 */
class HostSessionGuard extends SessionGuard
{
    public function getRecallerName()
    {
        return HostCookies::name('remember_'.$this->name);
    }
}
