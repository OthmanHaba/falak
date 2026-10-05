<?php

namespace Falak\SourceControl\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\SourceControl\Application\Actions\ConnectGitHubInstallation;
use Falak\SourceControl\Application\Actions\DeleteGitHubApp;
use Falak\SourceControl\Application\Actions\RegisterGitHubApp;
use Falak\SourceControl\Contracts\Exceptions\SourceControlException;
use Falak\SourceControl\Domain\Models\Connection;
use Falak\SourceControl\Domain\Models\GitHubApp;
use Falak\SourceControl\Http\Controllers\Concerns\ConnectFlowState;
use Falak\SourceControl\Infrastructure\GitHubApp\AppCredentials;
use Falak\SourceControl\Infrastructure\GitHubApp\AppManifest;
use Falak\SourceControl\Infrastructure\GitHubApp\GitHubAppResolver;

/**
 * "Connect GitHub" through a GitHub App:
 *
 * 1. {@see manifest()} — builds the app manifest + a state; the browser POSTs it to GitHub (personal account or an
 *    organization), where the user confirms the new app.
 * 2. {@see manifestCallback()} — GitHub redirects back with a code; Falak converts it into the app's credentials
 *    (stored encrypted) and sends the user straight to the app's installation page.
 * 3. {@see setup()} — GitHub's setup URL after installing (and, with `setup_on_update`, after changing repository
 *    access): the installation becomes a connection.
 */
final class GitHubAppController extends Controller
{
    use ConnectFlowState;

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly GitHubAppResolver $apps,
    ) {}

    public function manifest(Request $request, AppManifest $manifest): JsonResponse
    {
        $organizationId = $this->authorizeManage($request);
        $data = $request->validate([
            'organization' => ['nullable', 'string', 'max:39', 'regex:'.AppManifest::OWNER_PATTERN],
            'return_to' => ['nullable', 'string', 'max:500'],
        ], ['organization.regex' => 'Enter a GitHub organization name (letters, digits and single hyphens).']);

        if ($this->apps->env() !== null) {
            return response()->json(['message' => 'This Falak instance uses the GitHub App configured by its operator.'], 409);
        }

        if (GitHubApp::query()->where('organization_id', $organizationId)->exists()) {
            return response()->json(['message' => 'This organization already has a GitHub App.'], 409);
        }

        $appKey = strtolower((string) Str::ulid());
        $owner = ($data['organization'] ?? null) ?: null;
        $state = $this->remember($request, 'github-app-manifest', $organizationId, ['app' => $appKey, 'owner' => $owner, 'return_to' => self::safeReturn($data['return_to'] ?? null)]);

        return response()->json(['data' => [
            'action' => $manifest->formAction($state, $owner),
            'manifest' => $manifest->build($appKey),
        ]]);
    }

    public function manifestCallback(Request $request, RegisterGitHubApp $register): RedirectResponse
    {
        $organizationId = $this->authorizeManage($request);
        $flow = $this->verifyState($request, 'github-app-manifest', $organizationId);

        $code = (string) $request->query('code', '');
        abort_if($code === '' || ! isset($flow['app']), 400, 'Missing app setup code.');

        try {
            $app = $register($organizationId, $request->user()?->getAuthIdentifier(), (string) $flow['app'], $code);
        } catch (SourceControlException $e) {
            return to_route('source-control.index')->withErrors(['github_app' => $e->getMessage()]);
        }

        // Straight on to the installation: the user picks repositories and grants access on GitHub.
        return redirect()->away($this->installUrl($request, GitHubAppResolver::fromModel($app), $organizationId, $flow['return_to'] ?? null));
    }

    public function install(Request $request): RedirectResponse
    {
        $organizationId = $this->authorizeManage($request);
        $app = $this->apps->forOrganization($organizationId);

        abort_unless($app !== null && $app->installable(), 404);

        return redirect()->away($this->installUrl($request, $app, $organizationId, self::safeReturn($request->query('return_to'))));
    }

    public function setup(Request $request, ConnectGitHubInstallation $connect): RedirectResponse
    {
        $organizationId = $this->authorizeManage($request);
        $installationId = (string) $request->query('installation_id', '');
        $hasState = $request->filled('state');
        $flow = $hasState ? $this->verifyState($request, 'github-app', $organizationId) : [];
        $done = fn () => ($flow['return_to'] ?? null) ? redirect((string) $flow['return_to']) : to_route('source-control.index');

        if ($request->query('setup_action') === 'request') {
            return $done()->with('success', 'Installation requested. An owner of the GitHub organization has to approve it.');
        }

        abort_unless(ctype_digit($installationId), 400, 'Missing installation id.');

        $existing = Connection::query()
            ->where('organization_id', $organizationId)
            ->where('auth_type', 'app')
            ->where('installation_id', $installationId)
            ->first();

        // Changes made on GitHub (setup_on_update) come back without a state: they may only refresh a connection
        // this organization already has. New installations must start in Falak.
        abort_unless($hasState || $existing !== null, 403, 'Start the GitHub installation from Falak (Settings → Source control).');

        $app = $existing ? $this->apps->forConnection($existing) : $this->apps->forOrganization($organizationId);
        abort_unless($app !== null, 404);

        try {
            $connect($app, $organizationId, $request->user()?->getAuthIdentifier(), $installationId);
        } catch (SourceControlException $e) {
            return to_route('source-control.index')->withErrors(['github_app' => $e->getMessage()]);
        }

        return $done()->with('success', $existing ? 'GitHub repository access updated.' : 'GitHub connected.');
    }

    public function destroy(Request $request, DeleteGitHubApp $delete): RedirectResponse
    {
        $organizationId = $this->authorizeManage($request);
        $app = GitHubApp::query()->where('organization_id', $organizationId)->firstOrFail();

        $request->validate(['name' => ['required', 'string', Rule::in([$app->name])]], ['name.in' => 'Type the app name to confirm.']);

        $delete($app);

        return to_route('source-control.index')->with('success', 'GitHub App removed from Falak. Delete it on GitHub too.');
    }

    private function installUrl(Request $request, AppCredentials $app, string $organizationId, ?string $returnTo): string
    {
        $state = $this->remember($request, 'github-app', $organizationId, ['return_to' => $returnTo]);

        return rtrim((string) config('source_control.github.url'), '/')."/apps/{$app->slug}/installations/new?".http_build_query(['state' => $state]);
    }

    private function authorizeManage(Request $request): string
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'source_control.manage');

        return $organizationId;
    }

    /** Only same-site paths (no open redirects). */
    private static function safeReturn(mixed $path): ?string
    {
        return is_string($path) && str_starts_with($path, '/') && ! str_starts_with($path, '//') && ! str_contains($path, '\\') ? $path : null;
    }
}
