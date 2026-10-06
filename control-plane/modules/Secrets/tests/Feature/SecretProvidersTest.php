<?php

use Falak\Identity\Contracts\Role;
use Falak\Secrets\Application\Providers\ProviderValueCache;
use Falak\Secrets\Contracts\Data\ScopeChain;
use Falak\Secrets\Contracts\Exceptions\SecretProviderUnavailable;
use Falak\Secrets\Contracts\SecretProviders;
use Falak\Secrets\Contracts\Secrets;
use Falak\Secrets\Domain\Enums\ProviderStatus;
use Falak\Secrets\Domain\Enums\ProviderType;
use Falak\Secrets\Domain\Models\ProviderValue;
use Falak\Secrets\Events\ProviderRecovered;
use Falak\Secrets\Events\ProviderUnreachable;
use Falak\Secrets\Infrastructure\ExternalSecretProviders;
use Falak\Secrets\Infrastructure\Providers\ProviderFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = memberOf();
    $this->chain = new ScopeChain($this->organization->id);
    secrets_guard();
    Http::preventStrayRequests();
});

function secrets_resolve(ScopeChain $chain, string $name): string
{
    $resolved = app(Secrets::class)->resolve($chain, [$name]);

    return $resolved->values[$name] ?? throw new RuntimeException($resolved->errors[$name] ?? 'unresolved');
}

it('reads Vault KV v2 with a token and a namespace, and KV v1', function () {
    Http::fake([
        'https://vault.example.com/v1/kv/data/app/prod' => Http::response(['data' => ['data' => ['DB_PASS' => 'v2-secret', 'PORT' => 5432]]]),
        'https://vault.example.com/v1/secret/app' => Http::response(['data' => ['token' => 'v1-secret']]),
    ]);

    $v2 = secrets_vault($this->organization, ['namespace' => 'team/a']);
    secrets_linked($this->organization, 'DB_PASS', 'vault://kv/data/app/prod#DB_PASS', $v2);
    secrets_linked($this->organization, 'DB_PORT', 'vault://kv/data/app/prod#PORT', $v2);
    $v1 = secrets_vault($this->organization, ['kv_version' => '1'], ['name' => 'Legacy']);
    secrets_linked($this->organization, 'LEGACY', 'vault://secret/app#token', $v1);

    expect(secrets_resolve($this->chain, 'DB_PASS'))->toBe('v2-secret')
        ->and(secrets_resolve($this->chain, 'DB_PORT'))->toBe('5432')
        ->and(secrets_resolve($this->chain, 'LEGACY'))->toBe('v1-secret');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://vault.example.com/v1/kv/data/app/prod'
        && $request->header('X-Vault-Token') === ['hvs.ROOT-TOKEN'] && $request->header('X-Vault-Namespace') === ['team/a']);
    expect($v2->refresh()->status)->toBe(ProviderStatus::Ok);
});

it('logs in to Vault with AppRole once per lease and again after a refusal', function () {
    Http::fake([
        'https://vault.example.com/v1/auth/approle/login' => Http::sequence()
            ->push(['auth' => ['client_token' => 'hvs.CLIENT-1', 'lease_duration' => 3600]])
            ->push(['auth' => ['client_token' => 'hvs.CLIENT-2', 'lease_duration' => 3600]]),
        'https://vault.example.com/v1/kv/data/app' => Http::sequence()
            ->push(['data' => ['data' => ['K' => 'one']]])
            ->push(['data' => ['data' => ['K' => 'two']]])
            ->push(['errors' => ['permission denied']], 403)
            ->push(['data' => ['data' => ['K' => 'four']]]),
    ]);

    $provider = secrets_vault($this->organization, ['auth_method' => 'approle', 'role_id' => 'role-1', 'secret_id' => 'SECRET-ID-VALUE', 'token' => ''], ['cache_ttl_seconds' => 0]);
    $providers = app(ExternalSecretProviders::class);

    expect($providers->refresh('vault://kv/data/app#K', $provider->id, $this->organization->id))->toBe('one')
        ->and($providers->refresh('vault://kv/data/app#K', $provider->id, $this->organization->id))->toBe('two');

    // A revoked client token: refused once, the next lookup logs in again.
    expect(fn () => $providers->refresh('vault://kv/data/app#K', $provider->id, $this->organization->id))->toThrow(SecretProviderUnavailable::class, 'refused access');
    expect($providers->refresh('vault://kv/data/app#K', $provider->id, $this->organization->id))->toBe('four');

    $logins = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/login'));
    expect($logins)->toHaveCount(2)
        ->and($logins[0][0]->data())->toBe(['role_id' => 'role-1', 'secret_id' => 'SECRET-ID-VALUE']);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/kv/data/app') && $request->header('X-Vault-Token') === ['hvs.CLIENT-2']);
});

it('logs in to Vault with a JWT role on a custom mount', function () {
    Http::fake([
        'https://vault.example.com/v1/auth/oidc-ci/login' => Http::response(['auth' => ['client_token' => 'hvs.JWT', 'lease_duration' => 600]]),
        'https://vault.example.com/v1/kv/data/app' => Http::response(['data' => ['data' => ['K' => 'jwt-value']]]),
    ]);

    $provider = secrets_vault($this->organization, ['auth_method' => 'jwt', 'role' => 'falak', 'jwt' => 'eyJ.JWT.TOKEN', 'auth_mount' => 'oidc-ci']);

    expect(app(SecretProviders::class)->resolve('vault://kv/data/app#K', $provider->id, $this->organization->id))->toBe('jwt-value');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/auth/oidc-ci/login') && $request->data() === ['role' => 'falak', 'jwt' => 'eyJ.JWT.TOKEN']);
});

it('reads AWS Secrets Manager and SSM with SigV4, assuming a role', function () {
    Http::fake([
        'https://sts.eu-west-1.amazonaws.com/' => Http::response(['AssumeRoleResponse' => ['AssumeRoleResult' => ['Credentials' => [
            'AccessKeyId' => 'ASIAASSUMED', 'SecretAccessKey' => 'assumed-secret', 'SessionToken' => 'SESSION-TOKEN', 'Expiration' => 1_900_000_000,
        ]]]]),
        'https://secretsmanager.eu-west-1.amazonaws.com/' => Http::response(['SecretString' => '{"password":"sm-secret","user":"app"}']),
        'https://ssm.eu-west-1.amazonaws.com/' => Http::response(['Parameter' => ['Name' => '/prod/db/pass', 'Value' => 'ssm-secret']]),
    ]);

    $aws = ['region' => 'eu-west-1', 'auth_method' => 'keys', 'access_key_id' => 'AKIAEXAMPLEKEY12', 'secret_access_key' => 'base-secret-key', 'role_arn' => 'arn:aws:iam::123456789012:role/falak'];
    $sm = secrets_provider($this->organization, ProviderType::AwsSecretsManager, $aws);
    $ssm = secrets_provider($this->organization, ProviderType::AwsSsm, $aws);
    secrets_linked($this->organization, 'DB_PASSWORD', 'aws-sm://prod/db#password', $sm);
    secrets_linked($this->organization, 'SSM_PASS', 'aws-ssm://prod/db/pass', $ssm);

    expect(secrets_resolve($this->chain, 'DB_PASSWORD'))->toBe('sm-secret')
        ->and(secrets_resolve($this->chain, 'SSM_PASS'))->toBe('ssm-secret');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'sts.') && str_contains($request->body(), 'Action=AssumeRole')
        && str_contains($request->header('Authorization')[0], 'Credential=AKIAEXAMPLEKEY12/'));
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'secretsmanager.') && $request->header('X-Amz-Target') === ['secretsmanager.GetSecretValue']
        && str_starts_with($request->header('Authorization')[0], 'AWS4-HMAC-SHA256 Credential=ASIAASSUMED/')
        && $request->header('x-amz-security-token') === ['SESSION-TOKEN'] && json_decode($request->body(), true) === ['SecretId' => 'prod/db']);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'ssm.') && json_decode($request->body(), true) === ['Name' => '/prod/db/pass', 'WithDecryption' => true]);
    // Each provider assumed the role once.
    expect(Http::recorded(fn (Request $request) => str_contains($request->url(), 'sts.')))->toHaveCount(2);
});

it('names AWS errors without echoing the response', function () {
    Http::fake(['https://secretsmanager.us-east-1.amazonaws.com/' => Http::response(['__type' => 'ResourceNotFoundException', 'Message' => 'Secrets Manager can\'t find the specified secret. hunter2'], 400)]);
    $sm = secrets_provider($this->organization, ProviderType::AwsSecretsManager, ['region' => 'us-east-1', 'access_key_id' => 'AKIAEXAMPLEKEY12', 'secret_access_key' => 'base-secret-key']);

    try {
        app(SecretProviders::class)->resolve('aws-sm://missing', $sm->id, $this->organization->id);
        $this->fail('Expected SecretProviderUnavailable');
    } catch (SecretProviderUnavailable $e) {
        expect($e->getMessage())->toContain('aws-sm://missing was not found')->not->toContain('hunter2')->not->toContain('base-secret-key');
    }
});

it('reads 1Password through a Connect server by vault, item and field label', function () {
    Http::fake([
        'https://op.example.com/v1/vaults?*' => Http::response([['id' => 'vault-1', 'name' => 'Production']]),
        'https://op.example.com/v1/vaults/vault-1/items?*' => Http::response([['id' => 'item-1', 'title' => 'Stripe']]),
        'https://op.example.com/v1/vaults/vault-1/items/item-1' => Http::response([
            'sections' => [['id' => 's1', 'label' => 'live']],
            'fields' => [
                ['id' => 'f1', 'label' => 'secret key', 'value' => 'sk_test_x'],
                ['id' => 'f2', 'label' => 'secret key', 'value' => 'sk_live_y', 'section' => ['id' => 's1']],
            ],
        ]),
    ]);

    $provider = secrets_provider($this->organization, ProviderType::OnePassword, ['connect_url' => 'https://op.example.com', 'token' => 'CONNECT-TOKEN']);
    $providers = app(SecretProviders::class);

    expect($providers->resolve('op://Production/Stripe/secret key', $provider->id, $this->organization->id))->toBe('sk_test_x')
        ->and($providers->resolve('op://Production/Stripe/live/secret key', $provider->id, $this->organization->id))->toBe('sk_live_y')
        ->and(fn () => $providers->resolve('op://Production/Stripe/nope', $provider->id, $this->organization->id))->toThrow(SecretProviderUnavailable::class, 'no field "nope"');

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://op.example.com/v1/vaults?filter=')
        && urldecode(parse_url($request->url(), PHP_URL_QUERY)) === 'filter=name eq "Production"' && $request->header('Authorization') === ['Bearer CONNECT-TOKEN']);
});

it('reads Doppler with a service token', function () {
    Http::fake(['https://api.doppler.com/v3/configs/config/secret?*' => Http::response(['name' => 'STRIPE', 'value' => ['raw' => '${OTHER}', 'computed' => 'doppler-value']])]);
    $provider = secrets_provider($this->organization, ProviderType::Doppler, ['token' => 'dp.st.SERVICE']);

    expect(app(SecretProviders::class)->resolve('doppler://backend/prd/STRIPE', $provider->id, $this->organization->id))->toBe('doppler-value');
    Http::assertSent(fn (Request $request) => $request['project'] === 'backend' && $request['config'] === 'prd' && $request['name'] === 'STRIPE'
        && $request->header('Authorization') === ['Bearer dp.st.SERVICE']);
});

it('reads a self-hosted Infisical with universal auth on a private network when allowed', function () {
    secrets_guard(['infisical.lan' => ['10.0.0.20']]);
    Http::fake([
        'https://infisical.lan/api/v1/auth/universal-auth/login' => Http::response(['accessToken' => 'ACCESS-TOKEN', 'expiresIn' => 7200]),
        'https://infisical.lan/api/v3/secrets/raw/DB_PASS?*' => Http::response(['secret' => ['secretKey' => 'DB_PASS', 'secretValue' => 'infisical-value']]),
    ]);

    $config = ['base_url' => 'https://infisical.lan', 'client_id' => 'client-1', 'client_secret' => 'CLIENT-SECRET'];
    expect(fn () => secrets_provider($this->organization, ProviderType::Infisical, $config))->toThrow(ValidationException::class, 'private or reserved');

    $provider = secrets_provider($this->organization, ProviderType::Infisical, $config, ['allow_private_network' => true]);

    expect(app(SecretProviders::class)->resolve('infisical://proj-1/prod/app/DB_PASS', $provider->id, $this->organization->id))->toBe('infisical-value');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/secrets/raw/DB_PASS') && $request['workspaceId'] === 'proj-1'
        && $request['environment'] === 'prod' && $request['secretPath'] === '/app' && $request->header('Authorization') === ['Bearer ACCESS-TOKEN']);
});

it('reads a generic HTTPS webhook with its header', function () {
    Http::fake([
        'https://hook.example.com/v1?ref=app%2FDB_PASS' => Http::response(['value' => 'hook-value']),
        'https://hook.example.com/v1?ref=bad' => Http::response(['secret' => 'no value key']),
    ]);
    $provider = secrets_provider($this->organization, ProviderType::Http, ['base_url' => 'https://hook.example.com/v1/', 'header_name' => 'X-Api-Key', 'header_value' => 'HOOK-KEY']);
    $providers = app(SecretProviders::class);

    expect($providers->resolve('https://hook.example.com/v1/app/DB_PASS', $provider->id, $this->organization->id))->toBe('hook-value')
        ->and(fn () => $providers->resolve('https://hook.example.com/v1/bad', $provider->id, $this->organization->id))->toThrow(SecretProviderUnavailable::class, 'did not answer {"value"')
        // Credentials only ever go to the base URL.
        ->and(fn () => $providers->resolve('https://evil.example.net/v1/app', $provider->id, $this->organization->id))->toThrow(SecretProviderUnavailable::class, 'start with the provider\'s base URL');

    Http::assertSent(fn (Request $request) => $request->header('X-Api-Key') === ['HOOK-KEY']);
});

it('caches values sealed for the TTL, falls back to the last good value and alerts when the provider is down', function () {
    Event::fake([ProviderUnreachable::class, ProviderRecovered::class]);
    Log::spy();
    $GLOBALS['vault_up'] = true;
    Http::fake(function (Request $request) {
        if (! $GLOBALS['vault_up']) {
            throw new ConnectionException('cURL error 7: Failed to connect to vault.example.com port 443 with token hvs.ROOT-TOKEN');
        }

        return Http::response(['data' => ['data' => ['K' => 'cached-value']]]);
    });

    $provider = secrets_vault($this->organization, attributes: ['cache_ttl_seconds' => 300]);
    secrets_linked($this->organization, 'K', 'vault://kv/data/app#K', $provider);

    expect(secrets_resolve($this->chain, 'K'))->toBe('cached-value');
    $row = ProviderValue::query()->sole();
    expect($row->ciphertext)->toStartWith('fk1:')->not->toContain('cached-value');

    // Within the TTL: no request.
    $GLOBALS['vault_up'] = false;
    expect(secrets_resolve($this->chain, 'K'))->toBe('cached-value');
    Http::assertSentCount(1);

    // After it, the provider is down: the last good value, an alert and a warning without the value.
    $this->travel(301)->seconds();
    expect(secrets_resolve($this->chain, 'K'))->toBe('cached-value');
    Event::assertDispatched(ProviderUnreachable::class, fn (ProviderUnreachable $e) => $e->usedStale && $e->providerId === $provider->id && ! str_contains($e->error, 'hvs.'));
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => str_contains($message, 'last good value') && ! str_contains(json_encode($context), 'cached-value'));
    expect($provider->refresh()->status)->toBe(ProviderStatus::Error)
        ->and($provider->last_error)->toContain('unreachable')->not->toContain('hvs.');

    // Back up: a recovery clears the alert.
    $GLOBALS['vault_up'] = true;
    expect(secrets_resolve($this->chain, 'K'))->toBe('cached-value');
    Event::assertDispatched(ProviderRecovered::class);
    expect($provider->refresh()->status)->toBe(ProviderStatus::Ok);
});

it('fails with a clear message when the provider is down and nothing is cached', function () {
    Event::fake([ProviderUnreachable::class]);
    Http::fake(fn () => throw new ConnectionException('cURL error 28 hvs.ROOT-TOKEN'));
    $provider = secrets_vault($this->organization, ['namespace' => 'n']);
    secrets_linked($this->organization, 'K', 'vault://kv/data/app#K', $provider);

    $resolved = app(Secrets::class)->resolve($this->chain, ['K']);

    expect($resolved->errors['K'])->toBe('secret K: HashiCorp Vault / OpenBao: HashiCorp Vault / OpenBao at vault.example.com is unreachable.')
        ->and($resolved->values)->toBe([]);
    Event::assertDispatched(ProviderUnreachable::class, fn (ProviderUnreachable $e) => ! $e->usedStale);
});

it('retries once on 5xx and 429', function () {
    Http::fake(['https://vault.example.com/*' => Http::sequence()->push('down', 503)->push(['data' => ['data' => ['K' => 'after-retry']]])->push('slow', 429)->push('slow', 429)]);
    $provider = secrets_vault($this->organization, attributes: ['cache_ttl_seconds' => 0]);
    $providers = app(ExternalSecretProviders::class);

    expect($providers->refresh('vault://kv/data/app#K', $provider->id, $this->organization->id))->toBe('after-retry')
        ->and(fn () => $providers->refresh('vault://kv/data/app#K', $provider->id, $this->organization->id))->toThrow(SecretProviderUnavailable::class, 'rate limiting');
    Http::assertSentCount(4);
});

it('binds cached values to their provider, reference and organization', function () {
    Http::fake(['*' => Http::response(['data' => ['data' => ['K' => 'mine']]])]);
    $provider = secrets_vault($this->organization);
    app(SecretProviders::class)->resolve('vault://kv/data/app#K', $provider->id, $this->organization->id);
    $cache = app(ProviderValueCache::class);

    [, $stranger] = memberOf();
    $foreign = secrets_vault($stranger);
    ProviderValue::query()->create([
        'organization_id' => $stranger->id, 'provider_id' => $foreign->id, 'reference_hash' => ProviderValueCache::hash('vault://kv/data/app#K'),
        'ciphertext' => ProviderValue::query()->sole()->ciphertext, 'fetched_at' => now(),
    ]);

    expect($cache->get($provider, 'vault://kv/data/app#K')['value'])->toBe('mine')
        ->and($cache->get($provider, 'vault://kv/data/app#OTHER'))->toBeNull()
        ->and($cache->get($foreign, 'vault://kv/data/app#K'))->toBeNull();
});

it('refuses endpoints on metadata addresses always, and private ones unless allowed, also at request time', function () {
    secrets_guard(['metadata.example.com' => ['169.254.169.254'], 'lan.example.com' => ['192.168.1.10']]);

    expect(fn () => secrets_vault($this->organization, ['address' => 'https://metadata.example.com'], ['allow_private_network' => true]))->toThrow(ValidationException::class, 'never allowed')
        ->and(fn () => secrets_vault($this->organization, ['address' => 'https://lan.example.com']))->toThrow(ValidationException::class, 'private or reserved')
        ->and(fn () => secrets_vault($this->organization, ['address' => 'http://vault.example.com']))->toThrow(ValidationException::class, 'https://');

    // Saved while public; the DNS answer changes to the metadata address before the request.
    $provider = secrets_vault($this->organization, ['address' => 'https://rebind.example.com']);
    secrets_guard(['rebind.example.com' => ['169.254.169.254']]);
    Http::fake();

    expect(fn () => app(SecretProviders::class)->resolve('vault://kv/data/app#K', $provider->id, $this->organization->id))->toThrow(SecretProviderUnavailable::class, 'never allowed');
    Http::assertNothingSent();

    // Instance-wide switch: no private networks at all.
    config(['secrets.providers.allow_private_network' => false]);
    expect(fn () => secrets_vault($this->organization, ['address' => 'https://lan.example.com'], ['allow_private_network' => true, 'name' => 'LAN']))->toThrow(ValidationException::class, 'does not allow');
});

it('validates linked references against their provider', function () {
    $vault = secrets_vault($this->organization);
    $doppler = secrets_provider($this->organization, ProviderType::Doppler, ['token' => 'dp.st.X']);

    expect(fn () => secrets_linked($this->organization, 'A', 'doppler://p/c/K', $vault))->toThrow(ValidationException::class, 'its references look like vault://')
        ->and(fn () => secrets_linked($this->organization, 'B', 'vault://kv/app#K', $vault))->toThrow(ValidationException::class, '/data/')
        ->and(fn () => secrets_create($this->organization, 'C', '', attributes: ['kind' => 'linked', 'reference' => 'ftp://x']))->toThrow(ValidationException::class, 'provider scheme');

    // Without a provider id: the organization's only provider of that type.
    $secret = secrets_create($this->organization, 'D', '', attributes: ['kind' => 'linked', 'reference' => 'doppler://p/c/K']);
    expect($secret->provider_id)->toBe($doppler->id);

    secrets_provider($this->organization, ProviderType::Doppler, ['token' => 'dp.st.Y'], ['name' => 'Doppler 2']);
    expect(fn () => secrets_create($this->organization, 'E', '', attributes: ['kind' => 'linked', 'reference' => 'doppler://p/c/K']))->toThrow(ValidationException::class, 'choose one');
});

it('keeps providers to their organization', function () {
    Http::fake();
    [, $stranger] = memberOf();
    $foreign = secrets_vault($stranger);

    expect(fn () => secrets_linked($this->organization, 'X', 'vault://kv/data/app#K', $foreign))->toThrow(ValidationException::class, 'of this organization')
        ->and(fn () => app(SecretProviders::class)->resolve('vault://kv/data/app#K', $foreign->id, $this->organization->id))->toThrow(SecretProviderUnavailable::class, 'no longer exists')
        ->and(app(SecretProviders::class)->exists($foreign->id, $this->organization->id))->toBeFalse()
        ->and(app(SecretProviders::class)->exists($foreign->id, $stranger->id))->toBeTrue();

    $this->actingAs($this->user);
    $this->getJson('/settings/secrets/providers')->assertOk();
    $this->patchJson("/secrets/providers/{$foreign->id}", ['name' => 'Mine'])->assertNotFound();
    $this->postJson("/secrets/providers/{$foreign->id}/test")->assertNotFound();
    $this->deleteJson("/secrets/providers/{$foreign->id}")->assertNotFound();
    Http::assertNothingSent();
});

it('tests the connection without reading a value, and records the status', function () {
    Http::fake([
        'https://vault.example.com/v1/auth/token/lookup-self' => Http::sequence()->push(['data' => ['id' => 'x']])->push(['errors' => ['permission denied']], 403),
    ]);
    $provider = secrets_vault($this->organization);
    $providers = app(ExternalSecretProviders::class);

    $providers->test($provider);
    expect($provider->refresh()->status)->toBe(ProviderStatus::Ok)->and($provider->last_checked_at)->not->toBeNull();

    expect(fn () => $providers->test($provider))->toThrow(ProviderFailure::class, 'refused access');
    expect($provider->refresh()->status)->toBe(ProviderStatus::Error)->and($provider->last_error)->toContain('HTTP 403');
});

it('tests AWS credentials with GetCallerIdentity, and Doppler, Infisical, 1Password and webhooks with their own checks', function () {
    Http::fake([
        'https://sts.eu-central-1.amazonaws.com/' => Http::response(['GetCallerIdentityResponse' => ['GetCallerIdentityResult' => ['Account' => '123456789012']]]),
        'https://api.doppler.com/v3/me' => Http::response(['type' => 'service_token']),
        'https://app.infisical.com/api/v1/auth/universal-auth/login' => Http::response(['accessToken' => 'T', 'expiresIn' => 100]),
        'https://op.example.com/v1/vaults' => Http::response([]),
        'https://hook.example.com/v1?ref=__falak_connection_test__' => Http::response(['error' => 'unknown ref'], 404),
    ]);
    $providers = app(ExternalSecretProviders::class);

    foreach ([
        [ProviderType::AwsSsm, ['region' => 'eu-central-1', 'access_key_id' => 'AKIAEXAMPLEKEY12', 'secret_access_key' => 'S']],
        [ProviderType::Doppler, ['token' => 'dp.st.X']],
        [ProviderType::Infisical, ['client_id' => 'c', 'client_secret' => 's']],
        [ProviderType::OnePassword, ['connect_url' => 'https://op.example.com', 'token' => 'T']],
        [ProviderType::Http, ['base_url' => 'https://hook.example.com/v1']],
    ] as [$type, $config]) {
        $provider = secrets_provider($this->organization, $type, $config);
        $providers->test($provider);
        expect($provider->refresh()->status)->toBe(ProviderStatus::Ok);
    }

    Http::assertSent(fn (Request $request) => str_contains($request->body(), 'Action=GetCallerIdentity'));
});

it('manages providers over HTTP without ever returning credentials, and audits names only', function () {
    Http::fake(['https://vault.example.com/v1/auth/token/lookup-self' => Http::response(['data' => []])]);
    $this->actingAs($this->user);

    $created = $this->postJson('/secrets/providers', [
        'name' => 'Vault', 'type' => 'vault',
        'config' => ['address' => 'https://vault.example.com/', 'auth_method' => 'approle', 'role_id' => 'role-1', 'secret_id' => 'SECRET-ID-123', 'token' => 'ignored-token'],
    ])->assertCreated();
    $id = $created->json('data.id');

    expect($created->json('data.settings'))->toBe(['address' => 'https://vault.example.com', 'kv_version' => '2', 'auth_method' => 'approle', 'role_id' => 'role-1'])
        ->and($created->json('data.stored_credentials'))->toBe(['secret_id'])
        ->and($created->getContent())->not->toContain('SECRET-ID-123')->not->toContain('ignored-token');

    // Empty credentials keep the stored ones; a change of method drops the old method's settings.
    $this->patchJson("/secrets/providers/{$id}", ['config' => ['address' => 'https://vault.example.com', 'auth_method' => 'approle', 'role_id' => 'role-2', 'secret_id' => '']])->assertOk()
        ->assertJsonPath('data.settings.role_id', 'role-2')->assertJsonPath('data.stored_credentials', ['secret_id']);
    $this->patchJson("/secrets/providers/{$id}", ['config' => ['address' => 'https://vault.example.com', 'auth_method' => 'token']])->assertJsonValidationErrors('config.token');
    $this->patchJson("/secrets/providers/{$id}", ['config' => ['address' => 'https://vault.example.com', 'auth_method' => 'token', 'token' => 'hvs.NEW']])->assertOk()
        ->assertJsonPath('data.stored_credentials', ['token'])->assertJsonPath('data.status', 'untested');

    $this->postJson("/secrets/providers/{$id}/test")->assertOk()->assertJsonPath('data.status', 'ok');

    $page = $this->get('/settings/secrets/providers')->assertOk();
    expect($page->getContent())->not->toContain('hvs.NEW')->not->toContain('SECRET-ID-123');

    $audit = DB::table('identity_audit_log')->where('action', 'like', 'secret_provider.%')->get();
    expect($audit->pluck('action')->all())->toBe(['secret_provider.created', 'secret_provider.updated', 'secret_provider.updated'])
        ->and($audit->toJson())->not->toContain('SECRET-ID-123')->not->toContain('hvs.NEW')->toContain('role_id');

    // In use: refused; unused: deleted with its cached values.
    $secret = secrets_create($this->organization, 'K', '', attributes: ['kind' => 'linked', 'reference' => 'vault://kv/data/app#K', 'provider_id' => $id]);
    $this->deleteJson("/secrets/providers/{$id}")->assertJsonValidationErrors('provider');
    $this->deleteJson("/secrets/{$secret->id}")->assertNoContent();
    $this->deleteJson("/secrets/providers/{$id}")->assertNoContent();
});

it('answers a failed connection test with the reason only', function () {
    Http::fake(['*' => Http::response(['errors' => ['permission denied: token hvs.ROOT-TOKEN']], 403)]);
    $provider = secrets_vault($this->organization);
    $this->actingAs($this->user);

    $response = $this->postJson("/secrets/providers/{$provider->id}/test")->assertJsonValidationErrors('provider');

    expect($response->json('errors.provider.0'))->toContain('refused access')
        ->and($response->getContent())->not->toContain('hvs.ROOT-TOKEN');
});

it('lets viewers see providers but not change them', function () {
    $provider = secrets_vault($this->organization);
    [$viewer] = memberOf($this->organization, Role::Viewer);
    $this->actingAs($viewer);

    $this->get('/settings/secrets/providers')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Secrets/Providers', false)
        ->where('can.manage', false)->where('providers.0.name', $provider->name)->has('types', 7));
    $this->postJson('/secrets/providers', ['name' => 'X', 'type' => 'doppler', 'config' => ['token' => 't']])->assertForbidden();
    $this->patchJson("/secrets/providers/{$provider->id}", ['name' => 'Y'])->assertForbidden();
    $this->postJson("/secrets/providers/{$provider->id}/test")->assertForbidden();
});

it('manages providers with API tokens', function () {
    Http::fake(['https://api.doppler.com/v3/me' => Http::response([])]);
    $token = $this->user->createToken('cli', ['secrets.view', 'secrets.manage']);
    $token->accessToken->forceFill(['organization_id' => $this->organization->id])->save();

    $created = $this->withToken($token->plainTextToken)->postJson('/api/v1/secrets/providers', ['name' => 'Doppler', 'type' => 'doppler', 'config' => ['token' => 'dp.st.API']])->assertCreated();
    $id = $created->json('data.id');

    $this->withToken($token->plainTextToken)->getJson('/api/v1/secrets/providers')->assertOk()->assertJsonPath('data.0.name', 'Doppler');
    $this->withToken($token->plainTextToken)->postJson("/api/v1/secrets/providers/{$id}/test")->assertOk()->assertJsonPath('data.status', 'ok');
    $this->withToken($token->plainTextToken)->patchJson("/api/v1/secrets/providers/{$id}", ['cache_ttl_seconds' => 60])->assertOk()->assertJsonPath('data.cache_ttl_seconds', 60);
    expect($this->withToken($token->plainTextToken)->getJson("/api/v1/secrets/providers/{$id}")->getContent())->not->toContain('dp.st.API');

    [, $stranger] = memberOf();
    $this->withToken($token->plainTextToken)->getJson('/api/v1/secrets/providers/'.secrets_vault($stranger)->id)->assertNotFound();
    $this->withToken($token->plainTextToken)->deleteJson("/api/v1/secrets/providers/{$id}")->assertNoContent();
});

it('uses the control plane instance profile only when the instance allows it', function () {
    expect(fn () => secrets_provider($this->organization, ProviderType::AwsSsm, ['region' => 'eu-west-1', 'auth_method' => 'instance_profile']))->toThrow(ValidationException::class);

    config(['secrets.providers.allow_instance_profile' => true]);
    Http::fake([
        'http://169.254.169.254/latest/api/token' => Http::response('IMDS-TOKEN'),
        'http://169.254.169.254/latest/meta-data/iam/security-credentials/' => Http::response("falak-cp\n"),
        'http://169.254.169.254/latest/meta-data/iam/security-credentials/falak-cp' => Http::response(['AccessKeyId' => 'ASIAINSTANCE', 'SecretAccessKey' => 'instance-secret', 'Token' => 'INSTANCE-SESSION', 'Expiration' => now()->addHour()->toIso8601String()]),
        'https://ssm.eu-west-1.amazonaws.com/' => Http::response(['Parameter' => ['Value' => 'from-instance']]),
    ]);

    $provider = secrets_provider($this->organization, ProviderType::AwsSsm, ['region' => 'eu-west-1', 'auth_method' => 'instance_profile']);

    expect(app(SecretProviders::class)->resolve('aws-ssm://DB', $provider->id, $this->organization->id))->toBe('from-instance');
    Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && $request->header('X-aws-ec2-metadata-token-ttl-seconds') === ['21600']);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'ssm.') && $request->header('x-amz-security-token') === ['INSTANCE-SESSION']);
});
