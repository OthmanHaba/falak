<?php

namespace Falak\SourceControl\Http\Controllers;

use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\SourceControl\Application\Actions\CreateConnection;
use Falak\SourceControl\Contracts\Exceptions\SourceControlException;
use Falak\SourceControl\Contracts\ProviderType;
use Falak\SourceControl\Http\Controllers\Concerns\ConnectFlowState;
use Falak\SourceControl\Infrastructure\Providers\OAuthProviders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * OAuth connect flows (GitHub OAuth app, GitLab, Bitbucket); GitHub Apps live in {@see GitHubAppController}.
 * The `state` parameter binds the callback to the session + organization that started it.
 */
final class OAuthController extends Controller
{
    use ConnectFlowState;

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
}
