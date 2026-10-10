<?php

use Falak\Identity\Contracts\Role;
use Falak\SourceControl\Contracts\ProviderType;
use Falak\SourceControl\Contracts\SourceControlGateway;
use Falak\SourceControl\Domain\Models\Push;
use Falak\SourceControl\Domain\Models\Webhook;
use Falak\SourceControl\Events\PullRequestClosed;
use Falak\SourceControl\Events\PullRequestCommented;
use Falak\SourceControl\Events\PullRequestOpened;
use Falak\SourceControl\Events\PullRequestUpdated;
use Falak\SourceControl\Events\PushReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [, $this->organization] = memberOf(null, Role::Owner);
    Event::fake([PullRequestOpened::class, PullRequestUpdated::class, PullRequestClosed::class, PullRequestCommented::class, PushReceived::class]);
});

/**
 * @return array<string, mixed>
 */
function sc_github_pr(string $action, array $overrides = []): array
{
    return array_replace_recursive([
        'action' => $action,
        'number' => 7,
        'repository' => ['full_name' => 'acme/shop'],
        'pull_request' => [
            'number' => 7,
            'title' => 'Checkout v2',
            'html_url' => 'https://github.com/acme/shop/pull/7',
            'merged' => false,
            'user' => ['login' => 'ada'],
            'head' => ['ref' => 'feature/checkout', 'sha' => str_repeat('c', 40), 'repo' => ['full_name' => 'acme/shop']],
            'base' => ['ref' => 'main', 'repo' => ['full_name' => 'acme/shop']],
        ],
    ], $overrides);
}

describe('GitHub repository webhooks', function () {
    beforeEach(function () {
        $this->webhook = sc_webhook(sc_connection($this->organization->id));
    });

    $post = fn (array $payload, string $event = 'pull_request', ?string $signature = null) => sc_post_webhook(test()->webhook, $payload, [
        'X-GitHub-Event' => $event,
        'X-Hub-Signature-256' => $signature ?? 'sha256='.sc_sign($payload),
    ]);

    it('announces opened, reopened, synchronized and closed pull requests', function () use ($post) {
        $post(sc_github_pr('opened'))->assertStatus(202)->assertJson(['received' => 0, 'pull_request_events' => 1]);
        $post(sc_github_pr('reopened'))->assertJson(['pull_request_events' => 1]);
        $post(sc_github_pr('synchronize', ['pull_request' => ['head' => ['sha' => str_repeat('d', 40)]]]))->assertJson(['pull_request_events' => 1]);
        $post(sc_github_pr('closed', ['pull_request' => ['merged' => true]]))->assertJson(['pull_request_events' => 1]);

        Event::assertDispatchedTimes(PullRequestOpened::class, 2);
        Event::assertDispatched(PullRequestOpened::class, fn (PullRequestOpened $e) => $e->organizationId === $this->organization->id
            && $e->provider === 'github'
            && $e->pullRequest->repository === 'acme/shop'
            && $e->pullRequest->number === 7
            && $e->pullRequest->title === 'Checkout v2'
            && $e->pullRequest->headBranch === 'feature/checkout'
            && $e->pullRequest->headSha === str_repeat('c', 40)
            && $e->pullRequest->baseBranch === 'main'
            && $e->pullRequest->author === 'ada'
            && $e->pullRequest->url === 'https://github.com/acme/shop/pull/7'
            && ! $e->pullRequest->isFork);
        Event::assertDispatched(PullRequestUpdated::class, fn (PullRequestUpdated $e) => $e->pullRequest->headSha === str_repeat('d', 40));
        Event::assertDispatched(PullRequestClosed::class, fn (PullRequestClosed $e) => $e->merged);
        Event::assertNotDispatched(PushReceived::class);
        expect(Push::query()->count())->toBe(0);
    });

    it('marks pull requests from forks, and from deleted forks', function () use ($post) {
        $post(sc_github_pr('opened', ['pull_request' => ['head' => ['repo' => ['full_name' => 'mallory/shop']]]]));
        $payload = sc_github_pr('opened');
        $payload['pull_request']['head']['repo'] = null;
        $post($payload);

        Event::assertDispatched(PullRequestOpened::class, fn (PullRequestOpened $e) => $e->pullRequest->isFork && $e->pullRequest->sourceRepository === 'mallory/shop');
        Event::assertDispatched(PullRequestOpened::class, fn (PullRequestOpened $e) => $e->pullRequest->isFork && $e->pullRequest->sourceRepository === null);
    });

    it('ignores other pull request actions and malformed payloads', function () use ($post) {
        $post(sc_github_pr('edited'))->assertJson(['pull_request_events' => 0]);
        $post(sc_github_pr('labeled'))->assertJson(['pull_request_events' => 0]);
        $post(['action' => 'opened', 'pull_request' => 'nope', 'repository' => ['full_name' => 'acme/shop']])->assertJson(['pull_request_events' => 0]);

        Event::assertNotDispatched(PullRequestOpened::class);
    });

    it('announces comments on pull requests, not on issues', function () use ($post) {
        $comment = fn (array $issue) => [
            'action' => 'created',
            'repository' => ['full_name' => 'acme/shop'],
            'issue' => ['number' => 7, ...$issue],
            'comment' => ['id' => 99, 'body' => '/falak preview', 'user' => ['login' => 'grace']],
        ];

        $post($comment(['pull_request' => ['url' => 'x']]), 'issue_comment')->assertJson(['pull_request_events' => 1]);
        $post($comment([]), 'issue_comment')->assertJson(['pull_request_events' => 0]);

        Event::assertDispatchedTimes(PullRequestCommented::class, 1);
        Event::assertDispatched(PullRequestCommented::class, fn (PullRequestCommented $e) => $e->number === 7 && $e->author === 'grace'
            && $e->body === '/falak preview' && $e->commentId === '99' && $e->repository === 'acme/shop');
    });

    it('rejects unsigned and mis-signed pull request deliveries', function () use ($post) {
        $post(sc_github_pr('opened'), signature: 'sha256='.hash_hmac('sha256', 'x', 'nope'))->assertUnauthorized();
        sc_post_webhook($this->webhook, sc_github_pr('opened'), ['X-GitHub-Event' => 'pull_request'])->assertUnauthorized();

        Event::assertNotDispatched(PullRequestOpened::class);
    });
});

describe('GitLab', function () {
    beforeEach(function () {
        $this->webhook = sc_webhook(sc_connection($this->organization->id, ProviderType::GitLab), 'acme/shop');
    });

    $mr = fn (string $action, array $attributes = []) => [
        'object_kind' => 'merge_request',
        'user' => ['username' => 'ada'],
        'project' => ['path_with_namespace' => 'acme/shop'],
        'object_attributes' => array_replace([
            'iid' => 3,
            'title' => 'Checkout v2',
            'url' => 'https://gitlab.com/acme/shop/-/merge_requests/3',
            'action' => $action,
            'source_branch' => 'feature/checkout',
            'target_branch' => 'main',
            'source_project_id' => 1,
            'target_project_id' => 1,
            'source' => ['path_with_namespace' => 'acme/shop'],
            'last_commit' => ['id' => str_repeat('e', 40)],
        ], $attributes),
    ];
    $post = fn (array $payload, string $event = 'Merge Request Hook', string $token = 'topsecret') => sc_post_webhook(test()->webhook, $payload, ['X-Gitlab-Event' => $event, 'X-Gitlab-Token' => $token]);

    it('announces merge request events verified by the token', function () use ($mr, $post) {
        $post($mr('open'))->assertStatus(202)->assertJson(['pull_request_events' => 1]);
        $post($mr('update', ['oldrev' => str_repeat('a', 40)]))->assertJson(['pull_request_events' => 1]);
        // A title edit carries no oldrev: nothing to redeploy.
        $post($mr('update'))->assertJson(['pull_request_events' => 0]);
        $post($mr('merge'))->assertJson(['pull_request_events' => 1]);
        $post($mr('close'))->assertJson(['pull_request_events' => 1]);
        $post($mr('open', ['source_project_id' => 2, 'source' => ['path_with_namespace' => 'mallory/shop']]));
        $post($mr('open'), token: 'wrong')->assertUnauthorized();

        Event::assertDispatched(PullRequestOpened::class, fn (PullRequestOpened $e) => $e->provider === 'gitlab' && $e->pullRequest->number === 3
            && $e->pullRequest->headSha === str_repeat('e', 40) && $e->pullRequest->headBranch === 'feature/checkout' && ! $e->pullRequest->isFork);
        Event::assertDispatched(PullRequestOpened::class, fn (PullRequestOpened $e) => $e->pullRequest->isFork && $e->pullRequest->sourceRepository === 'mallory/shop');
        Event::assertDispatchedTimes(PullRequestUpdated::class, 1);
        Event::assertDispatched(PullRequestClosed::class, fn (PullRequestClosed $e) => $e->merged);
        Event::assertDispatched(PullRequestClosed::class, fn (PullRequestClosed $e) => ! $e->merged);
    });

    it('announces notes on merge requests', function () use ($post) {
        $post([
            'object_kind' => 'note',
            'user' => ['username' => 'grace'],
            'project' => ['path_with_namespace' => 'acme/shop'],
            'object_attributes' => ['id' => 12, 'note' => '/falak preview', 'noteable_type' => 'MergeRequest'],
            'merge_request' => ['iid' => 3],
        ], 'Note Hook')->assertJson(['pull_request_events' => 1]);

        Event::assertDispatched(PullRequestCommented::class, fn (PullRequestCommented $e) => $e->number === 3 && $e->author === 'grace' && $e->commentId === '12');
    });
});

describe('Bitbucket', function () {
    beforeEach(function () {
        $this->webhook = sc_webhook(sc_connection($this->organization->id, ProviderType::Bitbucket), 'acme/shop');
    });

    $pr = fn (array $source = []) => [
        'repository' => ['full_name' => 'acme/shop'],
        'pullrequest' => [
            'id' => 5,
            'title' => 'Checkout v2',
            'links' => ['html' => ['href' => 'https://bitbucket.org/acme/shop/pull-requests/5']],
            'author' => ['nickname' => 'ada'],
            'source' => array_replace(['branch' => ['name' => 'feature/checkout'], 'commit' => ['hash' => 'abcdef123456'], 'repository' => ['full_name' => 'acme/shop']], $source),
            'destination' => ['branch' => ['name' => 'main'], 'repository' => ['full_name' => 'acme/shop']],
        ],
    ];
    $post = fn (array $payload, string $event, ?string $signature = null) => sc_post_webhook(test()->webhook, $payload, [
        'X-Event-Key' => $event,
        'X-Hub-Signature' => $signature ?? 'sha256='.sc_sign($payload),
    ]);

    it('announces pull request events verified by the signature', function () use ($pr, $post) {
        $post($pr(), 'pullrequest:created')->assertStatus(202)->assertJson(['pull_request_events' => 1]);
        $post($pr(), 'pullrequest:updated')->assertJson(['pull_request_events' => 1]);
        $post($pr(), 'pullrequest:fulfilled')->assertJson(['pull_request_events' => 1]);
        $post($pr(), 'pullrequest:rejected')->assertJson(['pull_request_events' => 1]);
        $post($pr(['repository' => ['full_name' => 'mallory/shop']]), 'pullrequest:created');
        $post($pr(), 'pullrequest:created', 'sha256=nope')->assertUnauthorized();

        Event::assertDispatched(PullRequestOpened::class, fn (PullRequestOpened $e) => $e->provider === 'bitbucket' && $e->pullRequest->number === 5
            && $e->pullRequest->headSha === 'abcdef123456' && ! $e->pullRequest->isFork && $e->pullRequest->author === 'ada');
        Event::assertDispatched(PullRequestOpened::class, fn (PullRequestOpened $e) => $e->pullRequest->isFork);
        Event::assertDispatched(PullRequestClosed::class, fn (PullRequestClosed $e) => $e->merged);
        Event::assertDispatched(PullRequestClosed::class, fn (PullRequestClosed $e) => ! $e->merged);
    });

    it('announces pull request comments', function () use ($pr, $post) {
        $post([...$pr(), 'comment' => ['id' => 44, 'content' => ['raw' => '/falak preview'], 'user' => ['nickname' => 'grace']]], 'pullrequest:comment_created')
            ->assertJson(['pull_request_events' => 1]);

        Event::assertDispatched(PullRequestCommented::class, fn (PullRequestCommented $e) => $e->number === 5 && $e->author === 'grace' && $e->commentId === '44');
    });
});

describe('GitHub App', function () {
    beforeEach(function () {
        $this->githubApp = sc_github_app($this->organization->id);
        $this->connection = sc_app_connection($this->organization->id, $this->githubApp);
    });

    it('announces pull requests of repositories Falak knows, verified with the app secret', function () {
        Webhook::query()->create(['organization_id' => $this->organization->id, 'connection_id' => $this->connection->id, 'repository' => 'acme/shop', 'secret' => 'unused', 'installed' => true]);
        $payload = fn (string $repository) => sc_github_pr('opened', ['repository' => ['full_name' => $repository], 'installation' => ['id' => 555]]);

        sc_post_app_webhook($this->githubApp->id, 'pull_request', $payload('ACME/shop'))->assertStatus(202)->assertJson(['received' => 1]);
        sc_post_app_webhook($this->githubApp->id, 'pull_request', $payload('acme/blog'))->assertJson(['received' => 0]);
        sc_post_app_webhook($this->githubApp->id, 'pull_request', $payload('acme/shop'), 'wrong')->assertUnauthorized();

        Event::assertDispatchedTimes(PullRequestOpened::class, 1);
        Event::assertDispatched(PullRequestOpened::class, fn (PullRequestOpened $e) => $e->connectionId === $this->connection->id && $e->pullRequest->repository === 'acme/shop');
    });
});

describe('gateway', function () {
    it('creates and edits one comment per pull request, and reports commit statuses (GitHub)', function () {
        Http::fake([
            '*/issues/7/comments' => Http::response(['id' => 1001], 201),
            '*/issues/comments/1001' => Http::response(['id' => 1001]),
            '*/issues/comments/2002' => Http::response([], 404),
            '*/statuses/*' => Http::response([], 201),
        ]);
        $connection = sc_connection($this->organization->id);
        $gateway = app(SourceControlGateway::class);

        expect($gateway->commentOnPullRequest($connection->id, 'acme/shop', 7, 'Preview ready'))->toBe('1001')
            ->and($gateway->commentOnPullRequest($connection->id, 'acme/shop', 7, 'Preview updated', '1001'))->toBe('1001')
            // Deleted on GitHub: posted again.
            ->and($gateway->commentOnPullRequest($connection->id, 'acme/shop', 7, 'Preview again', '2002'))->toBe('1001');

        $gateway->setCommitStatus($connection->id, 'acme/shop', str_repeat('c', 40), 'success', 'falak/preview', 'Preview is live', 'https://pr-7-web.prv.example.com');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/repos/acme/shop/issues/7/comments') && $r['body'] === 'Preview ready');
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/issues/comments/1001') && $r['body'] === 'Preview updated');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/repos/acme/shop/statuses/'.str_repeat('c', 40)) && $r['state'] === 'success'
            && $r['context'] === 'falak/preview' && $r['target_url'] === 'https://pr-7-web.prv.example.com');
    });

    it('uses merge request notes and pipeline statuses on GitLab', function () {
        Http::fake(['*/notes*' => Http::response(['id' => 5]), '*/statuses/*' => Http::response([])]);
        $connection = sc_connection($this->organization->id, ProviderType::GitLab);
        $gateway = app(SourceControlGateway::class);

        $gateway->commentOnPullRequest($connection->id, 'acme/shop', 3, 'Hi');
        $gateway->commentOnPullRequest($connection->id, 'acme/shop', 3, 'Hi again', '5');
        $gateway->setCommitStatus($connection->id, 'acme/shop', 'abc', 'failure', 'falak/preview', 'Failed');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/projects/acme%2Fshop/merge_requests/3/notes'));
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/merge_requests/3/notes/5'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/statuses/abc') && $r['state'] === 'failed' && $r['name'] === 'falak/preview');
    });

    it('uses pull request comments and build statuses on Bitbucket', function () {
        Http::fake(['*/comments*' => Http::response(['id' => 8]), '*/statuses/build' => Http::response([])]);
        $connection = sc_connection($this->organization->id, ProviderType::Bitbucket);
        $gateway = app(SourceControlGateway::class);

        $gateway->commentOnPullRequest($connection->id, 'acme/shop', 5, 'Hi');
        $gateway->setCommitStatus($connection->id, 'acme/shop', 'abcdef123456', 'pending', 'falak/preview', 'Deploying');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/repositories/acme/shop/pullrequests/5/comments') && $r['content']['raw'] === 'Hi');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/commit/abcdef123456/statuses/build') && $r['state'] === 'INPROGRESS' && $r['key'] === 'falak-preview' && $r['url'] !== '');
    });

    it('keeps a pinned webhook when push-to-deploy no longer needs it', function () {
        Http::fake(['*/hooks' => Http::response(['id' => 77], 201), '*/hooks/77' => Http::response([], 204)]);
        $connection = sc_connection($this->organization->id);
        $gateway = app(SourceControlGateway::class);

        $gateway->pinWebhook($connection->id, 'acme/shop');
        $gateway->removeWebhook($connection->id, 'acme/shop');
        expect(Webhook::query()->where('repository', 'acme/shop')->sole()->pinned)->toBeTrue();

        $gateway->pinWebhook($connection->id, 'acme/shop', false);
        $gateway->removeWebhook($connection->id, 'acme/shop');
        expect(Webhook::query()->count())->toBe(0);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/hooks/77'));
    });

    it('maps a provider login to the members who connected that account', function () {
        [$member] = memberOf($this->organization, Role::Developer);
        sc_connection($this->organization->id, attributes: ['account' => 'Grace', 'created_by' => $member->id]);
        sc_app_connection($this->organization->id, 'env', attributes: ['account' => 'mallory', 'created_by' => $member->id]);
        [, $other] = memberOf(null, Role::Owner);
        sc_connection($other->id, attributes: ['account' => 'grace', 'created_by' => 'someone-else']);
        $gateway = app(SourceControlGateway::class);

        expect($gateway->usersWithAccount($this->organization->id, 'github', 'grace'))->toBe([$member->id])
            ->and($gateway->usersWithAccount($this->organization->id, 'github', 'mallory'))->toBe([])
            ->and($gateway->usersWithAccount($this->organization->id, 'gitlab', 'grace'))->toBe([]);
    });
});
