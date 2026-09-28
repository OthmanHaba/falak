<?php

namespace Kiln\SourceControl\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The `state` of a connect flow (OAuth, GitHub App manifest, GitHub App installation): a random value kept in the
 * session together with the flow and organization that started it, single-use and valid for 15 minutes. It binds
 * the provider's redirect back to this browser session, user and organization (CSRF protection).
 */
trait ConnectFlowState
{
    private const STATE_SESSION_KEY = 'source_control.oauth';

    /**
     * @param  array<string, mixed>  $extra  flow data returned by {@see verifyState()}
     */
    private function remember(Request $request, string $flow, string $organizationId, array $extra = []): string
    {
        $state = Str::random(40);
        $request->session()->put(self::STATE_SESSION_KEY, [
            'state' => $state,
            'flow' => $flow,
            'organization_id' => $organizationId,
            'user_id' => (string) $request->user()?->getAuthIdentifier(),
            'at' => time(),
            'extra' => $extra,
        ]);

        return $state;
    }

    /**
     * @return array<string, mixed> the flow data given to {@see remember()}
     */
    private function verifyState(Request $request, string $flow, string $organizationId): array
    {
        $stored = $request->session()->pull(self::STATE_SESSION_KEY);
        $state = (string) $request->query('state', '');

        $valid = is_array($stored)
            && $state !== ''
            && hash_equals((string) ($stored['state'] ?? ''), $state)
            && ($stored['flow'] ?? null) === $flow
            && ($stored['organization_id'] ?? null) === $organizationId
            && ($stored['user_id'] ?? null) === (string) $request->user()?->getAuthIdentifier()
            && (int) ($stored['at'] ?? 0) > time() - 900;

        abort_unless($valid, 403, 'Invalid or expired OAuth state. Start the connection again.');

        return (array) ($stored['extra'] ?? []);
    }
}
