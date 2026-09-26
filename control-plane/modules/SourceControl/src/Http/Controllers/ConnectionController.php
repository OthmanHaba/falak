<?php

namespace Kiln\SourceControl\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Kernel\Http\Controller;
use Kiln\SourceControl\Application\Actions\CreateConnection;
use Kiln\SourceControl\Application\Actions\DeleteConnection;
use Kiln\SourceControl\Contracts\Data\BranchData;
use Kiln\SourceControl\Contracts\Data\RepositoryData;
use Kiln\SourceControl\Contracts\Exceptions\SourceControlException;
use Kiln\SourceControl\Contracts\ProviderType;
use Kiln\SourceControl\Contracts\SourceControlGateway;
use Kiln\SourceControl\Domain\Models\Connection;
use Kiln\SourceControl\Domain\Models\Push;
use Kiln\SourceControl\Infrastructure\Providers\GitHubAppTokens;
use Kiln\SourceControl\Infrastructure\Providers\OAuthProviders;

final class ConnectionController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
    ) {}

    public function index(Request $request, OAuthProviders $oauth, GitHubAppTokens $githubApp): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'source_control.view');

        $connections = Connection::query()
            ->where('organization_id', $organizationId)
            ->withCount(['deployKeys', 'webhooks'])
            ->orderBy('name')
            ->get();

        return Inertia::render('SourceControl/Index', [
            'connections' => $connections->map(fn (Connection $connection) => [
                'id' => $connection->id,
                'name' => $connection->name,
                'provider' => $connection->provider->value,
                'provider_label' => $connection->provider->label(),
                'auth_type' => $connection->auth_type,
                'account' => $connection->account,
                'base_url' => $connection->base_url,
                'deploy_keys_count' => (int) $connection->getAttribute('deploy_keys_count'),
                'webhooks_count' => (int) $connection->getAttribute('webhooks_count'),
                'created_at' => $connection->created_at->toIso8601String(),
            ])->values(),
            'pushes' => Push::query()
                ->where('organization_id', $organizationId)
                ->latest('received_at')
                ->limit(20)
                ->get()
                ->map(fn (Push $push) => [
                    'id' => $push->id,
                    'connection_id' => $push->connection_id,
                    'repository' => $push->repository,
                    'branch' => $push->branch,
                    'sha' => $push->sha,
                    'message' => strtok($push->message, "\n") ?: '',
                    'author' => $push->author_name,
                    'pusher' => $push->pusher,
                    'url' => $push->url,
                    'received_at' => $push->received_at->toIso8601String(),
                ])->values(),
            'providers' => collect(ProviderType::cases())->map(fn (ProviderType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'has_api' => $type->hasApi(),
                'oauth' => $oauth->configured($type),
            ])->values(),
            'githubApp' => $githubApp->configured() && config('source_control.github.app.slug'),
            'canManage' => $this->access->can($request->user(), $organizationId, 'source_control.manage'),
        ]);
    }

    public function store(Request $request, CreateConnection $create): RedirectResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'source_control.manage');

        $data = $request->validate([
            'provider' => ['required', Rule::enum(ProviderType::class)],
            'name' => ['nullable', 'string', 'max:100', Rule::unique('source_control_connections')->where('organization_id', $organizationId)],
            'auth_type' => ['required', Rule::in(['token', 'basic', 'none'])],
            'base_url' => ['nullable', 'url:https,http', 'max:255'],
            'token' => ['required_if:auth_type,token', 'nullable', 'string', 'max:2000'],
            'username' => ['required_if:auth_type,basic', 'nullable', 'string', 'max:255'],
            'password' => ['required_if:auth_type,basic', 'nullable', 'string', 'max:2000'],
        ]);

        $provider = ProviderType::from($data['provider']);
        $allowed = match ($provider) {
            ProviderType::GitHub, ProviderType::GitLab => ['token'],
            ProviderType::Bitbucket => ['basic', 'token'],
            ProviderType::Custom => ['none'],
        };

        if (! in_array($data['auth_type'], $allowed, true)) {
            return back()->withErrors(['auth_type' => "{$provider->label()} connections use: ".implode(', ', $allowed).'.']);
        }

        if ($provider === ProviderType::Bitbucket && ! empty($data['base_url'])) {
            return back()->withErrors(['base_url' => 'Bitbucket Server is not supported; use a custom git connection.']);
        }

        $credentials = match ($data['auth_type']) {
            'token' => ['token' => $data['token']],
            'basic' => ['username' => $data['username'], 'password' => $data['password']],
            default => [],
        };

        $create(
            $organizationId,
            $request->user()?->getAuthIdentifier(),
            $provider,
            $data['auth_type'],
            $credentials,
            $data['name'] ?? null,
            $data['base_url'] ?? null,
        );

        return to_route('source-control.index')->with('success', 'Connected.');
    }

    public function destroy(Request $request, Connection $connection, DeleteConnection $delete): RedirectResponse
    {
        $this->authorize('delete', $connection);

        $request->validate(['name' => ['required', 'string', Rule::in([$connection->name])]], ['name.in' => 'Type the connection name to confirm.']);

        $delete($connection);

        return to_route('source-control.index');
    }

    public function repositories(Request $request, Connection $connection, SourceControlGateway $gateway): JsonResponse
    {
        $this->authorize('view', $connection);
        $search = $request->validate(['search' => ['nullable', 'string', 'max:100']])['search'] ?? null;

        try {
            $repositories = $gateway->repositories($connection->id, $search);
        } catch (SourceControlException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => array_map(fn (RepositoryData $repo) => [
            'full_name' => $repo->fullName,
            'default_branch' => $repo->defaultBranch,
            'private' => $repo->private,
            'ssh_url' => $repo->sshUrl,
            'https_url' => $repo->httpsUrl,
            'web_url' => $repo->webUrl,
        ], $repositories)]);
    }

    public function branches(Request $request, Connection $connection, SourceControlGateway $gateway): JsonResponse
    {
        $this->authorize('view', $connection);
        $repository = $request->validate(['repository' => ['required', 'string', 'max:500']])['repository'];

        try {
            $branches = $gateway->branches($connection->id, $repository);
        } catch (SourceControlException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => array_map(fn (BranchData $branch) => [
            'name' => $branch->name,
            'sha' => $branch->sha,
            'protected' => $branch->protected,
        ], $branches)]);
    }
}
