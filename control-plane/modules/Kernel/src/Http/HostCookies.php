<?php

namespace Falak\Kernel\Http;

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The panel's session and CSRF cookies are `__Host-` prefixed: host-only (no Domain), Secure and Path=/. Preview
 * environments are served from a sibling of the panel's host (e.g. `*.prv.falak.sh` next to `cloud.falak.sh`), and
 * a page there could otherwise set a cookie for the shared registrable domain that the panel would read (session
 * fixation, a planted CSRF token). Browsers refuse to let any other host set a `__Host-` cookie.
 *
 * The CSRF cookie keeps Laravel's semantics (encrypted token, echoed in X-XSRF-TOKEN by axios / fetch) under the
 * prefixed name; config/session.php names the session cookie.
 */
class HostCookies extends ValidateCsrfToken
{
    public const SESSION = '__Host-falak_session';

    public const XSRF = '__Host-XSRF-TOKEN';

    protected function newCookie($request, $config)
    {
        return new Cookie(
            self::XSRF,
            $request->session()->token(),
            $this->availableAt(60 * $config['lifetime']),
            '/',
            null,
            true,
            false,
            false,
            $config['same_site'] ?? null,
            $config['partitioned'] ?? false,
        );
    }

    public static function serialized()
    {
        return EncryptCookies::serialized(self::XSRF);
    }
}
