<?php

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Kiln\Identity\Application\Actions\CreateApiToken;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Identity\Domain\Models\AuditEntry;

it('keeps the via_token name in audit context while redacting secrets', function () {
    [$user, $organization] = memberOf();
    $token = app(CreateApiToken::class)($user, $organization->id, 'ci-deployer', ['*']);

    $this->withToken($token->plainTextToken)->getJson('/api/v1/me')->assertOk();
    $user->withAccessToken($token->accessToken);
    $this->actingAs($user);

    app(AuditLog::class)->record('thing.done', context: ['api_token' => 'plain-secret', 'nested' => ['password' => 'x', 'name' => 'ok']], organizationId: $organization->id);

    $entry = AuditEntry::query()->where('action', 'thing.done')->firstOrFail();
    expect($entry->actor_type)->toBe('token')
        ->and($entry->context['via_token'])->toBe('ci-deployer')
        ->and($entry->context['api_token'])->toBe('[redacted]')
        ->and($entry->context['nested'])->toBe(['password' => '[redacted]', 'name' => 'ok']);
});

it('records personal events without an organization', function () {
    [$user] = memberOf();

    app(AuditLog::class)->recordPersonal('two_factor.enabled', $user->id, ['method' => 'totp']);

    $entry = AuditEntry::query()->where('action', 'two_factor.enabled')->firstOrFail();
    expect($entry->organization_id)->toBeNull()
        ->and($entry->actor_id)->toBe($user->id)
        ->and($entry->subject_id)->toBe($user->id);
});

it('resolves the client IP from X-Forwarded-For only for trusted proxies', function () {
    TrustProxies::at(['10.0.0.1']);

    try {
        $trusted = null;
        $untrusted = null;
        Route::get('/_ip-probe', fn (Request $request) => $request->ip())->middleware(TrustProxies::class);

        $trusted = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->get('/_ip-probe', ['X-Forwarded-For' => '203.0.113.9'])->getContent();
        $untrusted = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.3'])->get('/_ip-probe', ['X-Forwarded-For' => '203.0.113.9'])->getContent();

        expect($trusted)->toBe('203.0.113.9')->and($untrusted)->toBe('198.51.100.3');
    } finally {
        TrustProxies::flushState();
    }
});
