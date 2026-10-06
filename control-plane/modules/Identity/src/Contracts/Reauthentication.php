<?php

namespace Falak\Identity\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Step-up re-authentication for sensitive reads (e.g. revealing a secret): the user confirms their password, plus
 * a current two-factor code when 2FA is enabled. Session-based; API tokens never re-authenticate.
 */
interface Reauthentication
{
    /** Whether the session's user re-authenticated within the last $seconds. */
    public function confirmedWithin(Request $request, int $seconds): bool;

    /** Whether confirming needs a two-factor code (2FA is enabled for the user). */
    public function requiresCode(Authenticatable $user): bool;

    /**
     * Check the password (and code) and remember the confirmation in the session. Throttled.
     *
     * @throws ValidationException on `password` or `code`
     */
    public function confirm(Request $request, #[\SensitiveParameter] string $password, #[\SensitiveParameter] ?string $code): void;
}
