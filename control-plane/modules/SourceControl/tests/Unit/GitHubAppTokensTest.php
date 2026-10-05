<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Falak\SourceControl\Contracts\Exceptions\SourceControlException;
use Falak\SourceControl\Infrastructure\GitHubApp\AppCredentials;
use Falak\SourceControl\Infrastructure\Providers\GitHubAppTokens;
use Falak\SourceControl\Infrastructure\Providers\GitHubClient;
use phpseclib3\Crypt\RSA;

beforeEach(function () {
    $this->key = RSA::createKey(2048);
    $this->credentials = new AppCredentials('01jappappappappappappappapp', '12345', 'falak-test', $this->key->toString('PKCS1'), 'hook');
    Cache::flush();
});

it('signs an RS256 app JWT', function () {
    $jwt = app(GitHubAppTokens::class)->jwt($this->credentials, 1_700_000_000);
    [$header, $payload, $signature] = explode('.', $jwt);

    $decode = fn (string $part) => base64_decode(strtr($part, '-_', '+/'));

    expect(json_decode($decode($header), true))->toBe(['alg' => 'RS256', 'typ' => 'JWT'])
        ->and(json_decode($decode($payload), true))->toBe(['iat' => 1_699_999_940, 'exp' => 1_700_000_540, 'iss' => '12345'])
        ->and($this->key->getPublicKey()->withPadding(RSA::SIGNATURE_PKCS1)->withHash('sha256')->verify("{$header}.{$payload}", $decode($signature)))->toBeTrue();
});

it('rejects an invalid private key', function () {
    app(GitHubAppTokens::class)->jwt(new AppCredentials('env', '1', 'x', 'not a key'));
})->throws(SourceControlException::class, 'private key is invalid');

it('mints installation tokens and caches them for 50 minutes (GitHub issues them for an hour)', function () {
    $issued = 0;
    Http::fake(['api.github.com/app/installations/99/access_tokens' => function () use (&$issued) {
        $issued++;

        return Http::response(['token' => "ghs_{$issued}", 'expires_at' => now()->addHour()->toIso8601ZuluString()], 201);
    }]);

    $tokens = app(GitHubAppTokens::class);

    expect($tokens->installationToken($this->credentials, '99'))->toBe('ghs_1');
    $this->travel(49)->minutes();
    expect($tokens->installationToken($this->credentials, '99'))->toBe('ghs_1');
    $this->travel(2)->minutes();
    expect($tokens->installationToken($this->credentials, '99'))->toBe('ghs_2');

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_starts_with($request->header('Authorization')[0], 'Bearer ey'));
});

it('caches tokens per app', function () {
    $issued = 0;
    Http::fake(['api.github.com/app/installations/99/access_tokens' => function () use (&$issued) {
        $issued++;

        return Http::response(['token' => "ghs_{$issued}", 'expires_at' => now()->addHour()->toIso8601ZuluString()], 201);
    }]);

    $other = new AppCredentials('env', '777', 'other', $this->credentials->privateKey);
    $tokens = app(GitHubAppTokens::class);

    expect($tokens->installationToken($this->credentials, '99'))->toBe('ghs_1')
        ->and($tokens->installationToken($other, '99'))->toBe('ghs_2')
        ->and($tokens->installationToken($this->credentials, '99'))->toBe('ghs_1');

    $tokens->forget($this->credentials, '99');
    expect($tokens->installationToken($this->credentials, '99'))->toBe('ghs_3');
});

it('fails clearly when the installation is unknown', function () {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

    app(GitHubAppTokens::class)->installation($this->credentials, '1');
})->throws(SourceControlException::class, 'installation not found');

it('reads installation details', function () {
    Http::fake(['api.github.com/app/installations/7' => Http::response(['id' => 7, 'account' => ['login' => 'acme'], 'target_type' => 'Organization', 'suspended_at' => '2026-09-01T00:00:00Z'])]);

    expect(app(GitHubAppTokens::class)->installation($this->credentials, '7'))->toBe(['id' => 7, 'account' => 'acme', 'target_type' => 'Organization', 'suspended' => true]);
});

it('parses GitHub Link headers', function () {
    expect(GitHubClient::nextLink('<https://api.github.com/user/repos?page=2>; rel="next", <https://api.github.com/user/repos?page=5>; rel="last"'))->toBe('https://api.github.com/user/repos?page=2')
        ->and(GitHubClient::nextLink('<https://api.github.com/user/repos?page=1>; rel="prev"'))->toBeNull()
        ->and(GitHubClient::nextLink(''))->toBeNull();
});
