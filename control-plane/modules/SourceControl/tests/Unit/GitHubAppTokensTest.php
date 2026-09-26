<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Kiln\SourceControl\Contracts\Exceptions\SourceControlException;
use Kiln\SourceControl\Infrastructure\Providers\GitHubAppTokens;
use Kiln\SourceControl\Infrastructure\Providers\GitHubClient;
use phpseclib3\Crypt\RSA;

beforeEach(function () {
    $this->key = RSA::createKey(2048);
    config([
        'source_control.github.app.id' => '12345',
        'source_control.github.app.private_key' => str_replace("\n", '\n', $this->key->toString('PKCS1')),
    ]);
});

it('signs an RS256 app JWT', function () {
    $jwt = app(GitHubAppTokens::class)->jwt(1_700_000_000);
    [$header, $payload, $signature] = explode('.', $jwt);

    $decode = fn (string $part) => base64_decode(strtr($part, '-_', '+/'));

    expect(json_decode($decode($header), true))->toBe(['alg' => 'RS256', 'typ' => 'JWT'])
        ->and(json_decode($decode($payload), true))->toBe(['iat' => 1_699_999_940, 'exp' => 1_700_000_540, 'iss' => '12345'])
        ->and($this->key->getPublicKey()->withPadding(RSA::SIGNATURE_PKCS1)->withHash('sha256')->verify("{$header}.{$payload}", $decode($signature)))->toBeTrue();
});

it('exchanges and caches installation tokens', function () {
    Cache::flush();
    Http::fake(['api.github.com/app/installations/99/access_tokens' => Http::response(['token' => 'ghs_abc', 'expires_at' => now()->addHour()->toIso8601ZuluString()], 201)]);

    $tokens = app(GitHubAppTokens::class);

    expect($tokens->installationToken('99'))->toBe('ghs_abc')
        ->and($tokens->installationToken('99'))->toBe('ghs_abc');

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_starts_with($request->header('Authorization')[0], 'Bearer ey'));
});

it('fails clearly when the installation is unknown', function () {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

    app(GitHubAppTokens::class)->installation('1');
})->throws(SourceControlException::class, 'installation not found');

it('parses GitHub Link headers', function () {
    expect(GitHubClient::nextLink('<https://api.github.com/user/repos?page=2>; rel="next", <https://api.github.com/user/repos?page=5>; rel="last"'))->toBe('https://api.github.com/user/repos?page=2')
        ->and(GitHubClient::nextLink('<https://api.github.com/user/repos?page=1>; rel="prev"'))->toBeNull()
        ->and(GitHubClient::nextLink(''))->toBeNull();
});
