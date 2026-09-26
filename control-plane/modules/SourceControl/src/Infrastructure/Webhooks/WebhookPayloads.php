<?php

namespace Kiln\SourceControl\Infrastructure\Webhooks;

use DateTimeImmutable;
use Exception;
use Illuminate\Http\Request;
use Kiln\SourceControl\Contracts\Data\CommitData;
use Kiln\SourceControl\Contracts\ProviderType;
use Kiln\SourceControl\Infrastructure\Providers\BitbucketClient;

/**
 * Signature verification and push parsing for inbound webhooks, per provider.
 *
 * - GitHub: X-Hub-Signature-256 = "sha256=" . HMAC-SHA256(body, secret)
 * - GitLab: X-Gitlab-Token = secret
 * - Bitbucket Cloud: X-Hub-Signature = "sha256=" . HMAC-SHA256(body, secret)
 * - Custom (Gitea / Forgejo / generic): X-Gitea-Signature / X-Forgejo-Signature (hex HMAC),
 *   X-Hub-Signature-256 ("sha256=" HMAC) or X-Kiln-Token = secret
 */
class WebhookPayloads
{
    public function verify(ProviderType $provider, Request $request, string $secret): bool
    {
        $body = $request->getContent();
        $hmac = hash_hmac('sha256', $body, $secret);

        return match ($provider) {
            ProviderType::GitHub => self::equals('sha256='.$hmac, $request->header('X-Hub-Signature-256')),
            ProviderType::GitLab => self::equals($secret, $request->header('X-Gitlab-Token')),
            ProviderType::Bitbucket => self::equals('sha256='.$hmac, $request->header('X-Hub-Signature')),
            ProviderType::Custom => self::equals($hmac, $request->header('X-Gitea-Signature'))
                || self::equals($hmac, $request->header('X-Forgejo-Signature'))
                || self::equals('sha256='.$hmac, $request->header('X-Hub-Signature-256'))
                || self::equals($secret, $request->header('X-Kiln-Token')),
        };
    }

    /** Provider "ping" / test deliveries that should be acknowledged but not processed. */
    public function isPing(ProviderType $provider, Request $request): bool
    {
        return match ($provider) {
            ProviderType::GitHub => $request->header('X-GitHub-Event') === 'ping',
            ProviderType::Bitbucket => $request->header('X-Event-Key') === 'diagnostics:ping',
            ProviderType::GitLab => false,
            ProviderType::Custom => in_array($request->header('X-GitHub-Event') ?? $request->header('X-Gitea-Event'), ['ping'], true),
        };
    }

    /**
     * Branch pushes in the delivery (tag pushes, branch deletions and non-push events yield nothing).
     *
     * @return list<ParsedPush>
     */
    public function pushes(ProviderType $provider, Request $request): array
    {
        $payload = (array) $request->json()->all();

        return match ($provider) {
            ProviderType::GitHub => $request->header('X-GitHub-Event') === 'push' ? self::githubLike($payload) : [],
            ProviderType::GitLab => in_array($request->header('X-Gitlab-Event'), ['Push Hook', 'System Hook'], true) && ($payload['object_kind'] ?? 'push') === 'push' ? self::gitlab($payload) : [],
            ProviderType::Bitbucket => $request->header('X-Event-Key') === 'repo:push' ? self::bitbucket($payload) : [],
            ProviderType::Custom => in_array($request->header('X-Gitea-Event') ?? $request->header('X-Forgejo-Event') ?? $request->header('X-GitHub-Event') ?? 'push', ['push'], true) ? self::githubLike($payload) : [],
        };
    }

    /**
     * GitHub, Gitea, Forgejo and generic payloads: {ref, before, after, deleted, head_commit|commits, pusher}.
     *
     * @param  array<string, mixed>  $payload
     * @return list<ParsedPush>
     */
    private static function githubLike(array $payload): array
    {
        $branch = self::branch($payload['ref'] ?? null);
        $after = (string) ($payload['after'] ?? '');

        if ($branch === null || ($payload['deleted'] ?? false) === true || self::isZero($after)) {
            return [];
        }

        $commits = (array) ($payload['commits'] ?? []);
        $head = is_array($payload['head_commit'] ?? null) ? $payload['head_commit'] : (collect($commits)->firstWhere('id', $after) ?? end($commits));
        $head = is_array($head) ? $head : [];

        return [new ParsedPush(
            branch: $branch,
            commit: new CommitData(
                sha: $after !== '' ? $after : (string) ($head['id'] ?? ''),
                message: (string) ($head['message'] ?? ''),
                authorName: $head['author']['name'] ?? null,
                authorEmail: $head['author']['email'] ?? null,
                committedAt: self::date($head['timestamp'] ?? null),
                url: $head['url'] ?? null,
            ),
            pusher: $payload['pusher']['name'] ?? $payload['pusher']['login'] ?? $payload['pusher']['username'] ?? $payload['sender']['login'] ?? null,
            beforeSha: self::isZero((string) ($payload['before'] ?? '')) ? null : ($payload['before'] ?? null),
        )];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<ParsedPush>
     */
    private static function gitlab(array $payload): array
    {
        $branch = self::branch($payload['ref'] ?? null);
        $after = (string) ($payload['checkout_sha'] ?? $payload['after'] ?? '');

        if ($branch === null || self::isZero($after) || $after === '') {
            return [];
        }

        $head = collect((array) ($payload['commits'] ?? []))->firstWhere('id', $after) ?? [];

        return [new ParsedPush(
            branch: $branch,
            commit: new CommitData(
                sha: $after,
                message: (string) ($head['message'] ?? $head['title'] ?? ''),
                authorName: $head['author']['name'] ?? null,
                authorEmail: $head['author']['email'] ?? null,
                committedAt: self::date($head['timestamp'] ?? null),
                url: $head['url'] ?? null,
            ),
            pusher: $payload['user_username'] ?? $payload['user_name'] ?? null,
            beforeSha: self::isZero((string) ($payload['before'] ?? '')) ? null : ($payload['before'] ?? null),
        )];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<ParsedPush>
     */
    private static function bitbucket(array $payload): array
    {
        $pushes = [];
        $pusher = $payload['actor']['nickname'] ?? $payload['actor']['display_name'] ?? null;

        foreach ((array) ($payload['push']['changes'] ?? []) as $change) {
            $new = $change['new'] ?? null;

            if (! is_array($new) || ($new['type'] ?? null) !== 'branch' || ! is_array($new['target'] ?? null) || ($change['closed'] ?? false) === true) {
                continue;
            }

            $pushes[] = new ParsedPush(
                branch: (string) $new['name'],
                commit: BitbucketClient::toCommit($new['target']),
                pusher: $pusher,
                beforeSha: $change['old']['target']['hash'] ?? null,
            );
        }

        return $pushes;
    }

    private static function branch(mixed $ref): ?string
    {
        return is_string($ref) && str_starts_with($ref, 'refs/heads/') ? substr($ref, 11) : null;
    }

    private static function isZero(string $sha): bool
    {
        return $sha !== '' && trim($sha, '0') === '';
    }

    private static function equals(string $expected, ?string $given): bool
    {
        return $given !== null && $given !== '' && hash_equals($expected, $given);
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        try {
            return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
        } catch (Exception) {
            return null;
        }
    }
}
