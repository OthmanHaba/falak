<?php

namespace Kiln\SourceControl\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Kernel\Http\Controller;
use Kiln\SourceControl\Application\Actions\CreateConnection;
use Kiln\SourceControl\Contracts\Exceptions\SourceControlException;
use Kiln\SourceControl\Contracts\ProviderType;
use Kiln\SourceControl\Infrastructure\Providers\GitHubAppTokens;
use Kiln\SourceControl\Infrastructure\Providers\OAuthProviders;

/**
 * OAuth connect flows (GitHub OAuth app, GitLab, Bitbucket) and the GitHub App installation flow.
 * The `state` parameter binds the callback to the session + organization that started it.
 */
final class OAuthController extends Controller
{
    private const SESSION_KEY = 'source_control.oauth';

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly OAuthProviders $oauth,
    ) {}

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        $type = $this->oauthProvider($provider);
        $organizationId = $this->authorizeManage($request);

        abort_unless($this->oauth->configured($type), 404);

        $state = $this->remember($request, $type->value, $organizationId);

        return redirect()->away($this->oauth->authorizeUrl($type, $state, route('source-control.callback', $type->value)));
    }

    public function callback(Request $request, string $provider, CreateConnection $create): RedirectResponse
    {
        $type = $this->oauthProvider($provider);
        $organizationId = $this->authorizeManage($request);
        $this->verifyState($request, $type->value, $organizationId);

        if ($request->filled('error')) {
            return to_route('source-control.index')->withErrors(['oauth' => 'Authorization was cancelled: '.Str::limit((string) $request->query('error_description', $request->query('error')), 200)]);
        }

        $code = (string) $request->query('code', '');
        abort_if($code === '', 400, 'Missing authorization code.');

        try {
            $credentials = $this->oauth->exchange($type, $code, route('source-control.callback', $type->value));
            $create($organizationId, $request->user()?->getAuthIdentifier(), $type, 'oauth', $credentials);
        } catch (SourceControlException $e) {
            return to_route('source-control.index')->withErrors(['oauth' => $e->getMessage()]);
        } catch (ValidationException $e) {
            return to_route('source-control.index')->withErrors(['oauth' => collect($e->errors())->flatten()->first()]);
        }

        return to_route('source-control.index')->with('success', "{$type->label()} connected.");
    }

    public function githubApp(Request $request, GitHubAppTokens $app): RedirectResponse
    {
        $organizationId = $this->authorizeManage($request);
        $slug = (string) config('source_control.github.app.slug');

        abort_unless($app->configured() && $slug !== '', 404);

        $state = $this->remember($request, 'github-app', $organizationId);

        return redirect()->away(rtrim((string) config('source_control.github.url'), '/')."/apps/{$slug}/installations/new?".http_build_query(['state' => $state]));
    }

    public function githubAppSetup(Request $request, GitHubAppTokens $app, CreateConnection $create): RedirectResponse
    {
        $organizationId = $this->authorizeManage($request);
        $this->verifyState($request, 'github-app', $organizationId);

        $installationId = (string) $request->query('installation_id', '');
        abort_unless(ctype_digit($installationId), 400, 'Missing installation id.');

        try {
            // Proves the installation belongs to this app (the id alone is guessable).
            $installation = $app->installation($installationId);
            $create($organizationId, $request->user()?->getAuthIdentifier(), ProviderType::GitHub, 'app', ['installation_id' => $installationId], account: $installation['account'] ?: null, name: 'GitHub App ('.($installation['account'] ?: $installationId).')');
        } catch (SourceControlException $e) {
            return to_route('source-control.index')->withErrors(['oauth' => $e->getMessage()]);
        }

        return to_route('source-control.index')->with('success', 'GitHub App installed.');
    }

    private function oauthProvider(string $provider): ProviderType
    {
        $type = ProviderType::tryFrom($provider);
        abort_unless($type !== null && $type->hasApi(), 404);

        return $type;
    }

    private function authorizeManage(Request $request): string
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'source_control.manage');

        return $organizationId;
    }

    private function remember(Request $request, string $flow, string $organizationId): string
    {
        $state = Str::random(40);
        $request->session()->put(self::SESSION_KEY, ['state' => $state, 'flow' => $flow, 'organization_id' => $organizationId, 'at' => time()]);

        return $state;
    }

    private function verifyState(Request $request, string $flow, string $organizationId): void
    {
        $stored = $request->session()->pull(self::SESSION_KEY);
        $state = (string) $request->query('state', '');

        $valid = is_array($stored)
            && $state !== ''
            && hash_equals((string) ($stored['state'] ?? ''), $state)
            && ($stored['flow'] ?? null) === $flow
            && ($stored['organization_id'] ?? null) === $organizationId
            && (int) ($stored['at'] ?? 0) > time() - 900;

        abort_unless($valid, 403, 'Invalid or expired OAuth state. Start the connection again.');
    }
}
