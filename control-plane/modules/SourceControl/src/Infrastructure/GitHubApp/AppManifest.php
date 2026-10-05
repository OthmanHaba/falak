<?php

namespace Falak\SourceControl\Infrastructure\GitHubApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Falak\SourceControl\Contracts\Exceptions\SourceControlException;

/**
 * GitHub App Manifest flow (docs.github.com → "Registering a GitHub App from a manifest"): Falak POSTs a manifest
 * to github.com/settings/apps/new (or an organization's), the user confirms, GitHub redirects back with a
 * one-hour code that {@see convert()} exchanges for the app's id, private key and webhook secret.
 *
 * Least privilege: contents + metadata read (clone, list repositories and branches, read commits). Falak does
 * not report commit statuses or build pull-request previews, so it asks for nothing else. The `installation`
 * and `installation_repositories` events are delivered to every app without subscribing.
 */
class AppManifest
{
    public const PERMISSIONS = ['contents' => 'read', 'metadata' => 'read'];

    public const EVENTS = ['push'];

    /** GitHub login rules for users and organizations. */
    public const OWNER_PATTERN = '/^[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}$/';

    /** GitHub limits app names to 34 characters (and they must be unique across GitHub). */
    private const NAME_LIMIT = 34;

    /**
     * @param  string  $appKey  the id the app will be stored under (its webhook URL embeds it)
     * @return array<string, mixed>
     */
    public function build(string $appKey, ?string $suffix = null): array
    {
        $appUrl = rtrim((string) config('app.url'), '/');

        return [
            'name' => self::name((string) (parse_url($appUrl, PHP_URL_HOST) ?: 'Falak'), $suffix ?? Str::lower(Str::random(4))),
            'url' => $appUrl,
            'description' => "Deploys your repositories with Falak ({$appUrl}).",
            'hook_attributes' => ['url' => self::webhookUrl($appKey), 'active' => true],
            'redirect_url' => route('source-control.github-app.manifest.callback'),
            'setup_url' => route('source-control.github-app.setup'),
            'setup_on_update' => true,
            'public' => false,
            'request_oauth_on_install' => false,
            'default_permissions' => self::PERMISSIONS,
            'default_events' => self::EVENTS,
        ];
    }

    /** Form action for the manifest POST: the personal account, or an organization the user administers. */
    public function formAction(string $state, ?string $organization = null): string
    {
        $base = rtrim((string) config('source_control.github.url'), '/');
        $path = $organization ? '/organizations/'.rawurlencode($organization).'/settings/apps/new' : '/settings/apps/new';

        return $base.$path.'?'.http_build_query(['state' => $state]);
    }

    public static function webhookUrl(string $appKey): string
    {
        $base = config('source_control.webhook_url') ?: config('app.url');

        return rtrim((string) $base, '/').'/api/webhooks/source-control/github-app/'.$appKey;
    }

    /**
     * @return array{id: string, slug: string, name: string, owner_login: ?string, owner_type: ?string, html_url: ?string, client_id: ?string, client_secret: ?string, webhook_secret: string, pem: string}
     */
    public function convert(string $code): array
    {
        try {
            $response = Http::accept('application/vnd.github+json')
                ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
                ->timeout(20)
                // An empty body: Http::post() would send `[]`, which GitHub rejects with 422.
                ->withBody('', 'application/json')
                ->post(rtrim((string) config('source_control.github.api_url'), '/').'/app-manifests/'.rawurlencode($code).'/conversions');
        } catch (ConnectionException $e) {
            throw SourceControlException::provider('GitHub', 'Could not reach the API: '.$e->getMessage());
        }

        $body = (array) $response->json();

        if ($response->failed() || ! isset($body['id'], $body['pem']) || ! is_string($body['pem'])) {
            $detail = is_string($body['message'] ?? null) ? ' GitHub said: '.mb_substr($body['message'], 0, 300) : '';

            throw SourceControlException::provider('GitHub', ($response->status() === 404
                ? 'The GitHub App setup code expired or was already used. Start again.'
                : 'GitHub did not return the new app\'s credentials.').$detail, $response->status());
        }

        return [
            'id' => (string) $body['id'],
            'slug' => (string) ($body['slug'] ?? ''),
            'name' => (string) ($body['name'] ?? $body['slug'] ?? 'Falak'),
            'owner_login' => isset($body['owner']['login']) ? (string) $body['owner']['login'] : null,
            'owner_type' => isset($body['owner']['type']) ? (string) $body['owner']['type'] : null,
            'html_url' => isset($body['html_url']) ? (string) $body['html_url'] : null,
            'client_id' => isset($body['client_id']) ? (string) $body['client_id'] : null,
            'client_secret' => isset($body['client_secret']) ? (string) $body['client_secret'] : null,
            'webhook_secret' => (string) ($body['webhook_secret'] ?? ''),
            'pem' => $body['pem'],
        ];
    }

    private static function name(string $host, string $suffix): string
    {
        $tail = ") {$suffix}";
        $host = mb_substr($host, 0, self::NAME_LIMIT - mb_strlen('Falak (') - mb_strlen($tail));

        return "Falak ({$host}{$tail}";
    }
}
