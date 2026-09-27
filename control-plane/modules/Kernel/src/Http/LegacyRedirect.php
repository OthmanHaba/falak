<?php

namespace Kiln\Kernel\Http;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Permanent redirect from a pre-redesign URL to its new home (docs/UI_DESIGN.md §3), keeping the query string so
 * bookmarks, CLI `open`, alert links and deep links like `/providers?add=1` keep working.
 *
 *     Route::get('providers', LegacyRedirect::to('/settings/cloud-providers'));
 */
final class LegacyRedirect
{
    public static function to(string $path): Closure
    {
        return static fn (Request $request): RedirectResponse => redirect()->to(
            $path.($request->getQueryString() ? '?'.$request->getQueryString() : ''),
            301,
        );
    }
}
