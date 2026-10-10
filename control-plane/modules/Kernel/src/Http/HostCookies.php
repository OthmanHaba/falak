<?php

namespace Falak\Kernel\Http;

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The panel's session, CSRF and remember-me cookies are `__Host-` prefixed: host-only (no Domain), Secure and
 * Path=/. Preview environments are served from a sibling of the panel's host (e.g. `*.prv.falak.sh` next to
 * `cloud.falak.sh`), and a page there could otherwise set a cookie for the shared registrable domain that the panel
 * would read (session fixation, a planted CSRF token, a remember-me cookie). Browsers refuse to let any other host set
 * a `__Host-` cookie.
 *
 * The prefix needs Secure, so it applies when APP_URL is https (every install); a plain-HTTP dev install keeps the
 * unprefixed names and `falak-ctl doctor` warns about it.
 *
 * The CSRF cookie keeps Laravel's semantics (encrypted token, echoed in X-XSRF-TOKEN by axios / fetch) under the
 * prefixed name; config/session.php names the session cookie and HostSessionGuard the remember-me cookie.
 */
class HostCookies extends ValidateCsrfToken
{
    public const PREFIX = '__Host-';

    public const SESSION = '__Host-falak_session';

    public const XSRF = '__Host-XSRF-TOKEN';

    /** Cookies are Secure (and so `__Host-` prefixed): the panel is served over https. */
    public static function secure(): bool
    {
        // config/app.php loads before config/session.php, which asks this while the configuration loads.
        $url = app()->bound('config') ? config('app.url') : env('APP_URL');

        return str_starts_with(strtolower((string) ($url ?? 'https://localhost')), 'https://');
    }

    public static function name(string $name): string
    {
        return (self::secure() ? self::PREFIX : '').$name;
    }

    public static function sessionName(): string
    {
        return self::name('falak_session');
    }

    public static function xsrfName(): string
    {
        return self::name('XSRF-TOKEN');
    }

    protected function newCookie($request, $config)
    {
        return new Cookie(
            self::xsrfName(),
            $request->session()->token(),
            $this->availableAt(60 * $config['lifetime']),
            '/',
            null,
            self::secure(),
            false,
            false,
            $config['same_site'] ?? null,
            $config['partitioned'] ?? false,
        );
    }

    public static function serialized()
    {
        return EncryptCookies::serialized(self::xsrfName());
    }
}
