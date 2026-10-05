<?php

use Falak\Identity\Contracts\Role;
use Falak\SourceControl\Domain\Models\Connection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    config([
        'source_control.github.client_id' => 'gh-id', 'source_control.github.client_secret' => 'gh-secret',
        'source_control.gitlab.client_id' => 'gl-id', 'source_control.gitlab.client_secret' => 'gl-secret',
        'source_control.bitbucket.client_id' => 'bb-id', 'source_control.bitbucket.client_secret' => 'bb-secret',
    ]);
    [, $this->organization] = actingAsMember(Role::Admin);
});

function oauth_state(string $location): string
{
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return (string) $query['state'];
}

it('connects GitHub through the OAuth flow', function () {
    $location = $this->get('/source-control/connect/github')->assertRedirect()->headers->get('Location');

    expect($location)->toStartWith('https://github.com/login/oauth/authorize?')
        ->and($location)->toContain('client_id=gh-id')
        ->and($location)->toContain(urlencode(route('source-control.callback', 'github')));

    Http::fake([
        'github.com/login/oauth/access_token' => Http::response(['access_token' => 'gho_abc', 'token_type' => 'bearer', 'scope' => 'repo']),
        'api.github.com/user' => Http::response(['login' => 'ada']),
    ]);

    $this->get('/source-control/callback/github?code=xyz&state='.oauth_state($location))->assertRedirect('/settings/source-control')->assertSessionHasNoErrors();

    $connection = Connection::query()->sole();
    expect($connection->auth_type)->toBe('oauth')
        ->and($connection->account)->toBe('ada')
        ->and($connection->credential('access_token'))->toBe('gho_abc');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'access_token') && $r['code'] === 'xyz' && $r['client_secret'] === 'gh-secret');
});

it('stores GitLab refresh tokens and expiry', function () {
    $location = $this->get('/source-control/connect/gitlab')->headers->get('Location');
    expect($location)->toStartWith('https://gitlab.com/oauth/authorize?')->toContain('scope=api');

    Http::fake([
        'gitlab.com/oauth/token' => Http::response(['access_token' => 'gl-at', 'refresh_token' => 'gl-rt', 'expires_in' => 7200]),
        'gitlab.com/api/v4/user' => Http::response(['username' => 'ada']),
    ]);

    $this->get('/source-control/callback/gitlab?code=c&state='.oauth_state($location))->assertSessionHasNoErrors();

    $connection = Connection::query()->sole();
    expect($connection->credential('refresh_token'))->toBe('gl-rt')
        ->and($connection->credential('expires_at'))->toBeGreaterThan(time() + 7000);
});

it('exchanges Bitbucket codes with client basic auth', function () {
    $location = $this->get('/source-control/connect/bitbucket')->headers->get('Location');

    Http::fake([
        'bitbucket.org/site/oauth2/access_token' => Http::response(['access_token' => 'bb-at', 'refresh_token' => 'bb-rt', 'expires_in' => 3600]),
        'api.bitbucket.org/2.0/user' => Http::response(['username' => 'ada']),
    ]);

    $this->get('/source-control/callback/bitbucket?code=c&state='.oauth_state($location))->assertSessionHasNoErrors();

    expect(Connection::query()->sole()->name)->toBe('Bitbucket (ada)');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'access_token') && $r->hasHeader('Authorization', 'Basic '.base64_encode('bb-id:bb-secret')));
});

it('rejects forged or replayed state', function () {
    $location = $this->get('/source-control/connect/github')->headers->get('Location');
    Http::fake();

    $this->get('/source-control/callback/github?code=x&state=forged')->assertForbidden();
    // The state was consumed by the failed attempt.
    $this->get('/source-control/callback/github?code=x&state='.oauth_state($location))->assertForbidden();
    // State from another provider's flow.
    $gitlab = $this->get('/source-control/connect/gitlab')->headers->get('Location');
    $this->get('/source-control/callback/github?code=x&state='.oauth_state($gitlab))->assertForbidden();

    Http::assertNothingSent();
    expect(Connection::query()->count())->toBe(0);
});

it('reports failed token exchanges', function () {
    $location = $this->get('/source-control/connect/github')->headers->get('Location');
    Http::fake(['github.com/login/oauth/access_token' => Http::response(['error' => 'bad_verification_code', 'error_description' => 'The code passed is incorrect or expired.'])]);

    $this->get('/source-control/callback/github?code=x&state='.oauth_state($location))->assertRedirect('/settings/source-control')->assertSessionHasErrors('oauth');
    expect(Connection::query()->count())->toBe(0);
});

it('returns 404 for unconfigured or unknown providers', function () {
    config(['source_control.gitlab.client_id' => null]);

    $this->get('/source-control/connect/gitlab')->assertNotFound();
    $this->get('/source-control/connect/custom')->assertNotFound();
    $this->get('/source-control/connect/svn')->assertNotFound();
    $this->get('/source-control/connect/github-app')->assertNotFound();
});
