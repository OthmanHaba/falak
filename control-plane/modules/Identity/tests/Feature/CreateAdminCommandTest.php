<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Kiln\Identity\Domain\Models\User;

function runAdmin(array $args): array
{
    Artisan::call('kiln:admin', [...$args, '--json' => true]);

    return json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
}

it('creates a verified admin with a personal organization and generated password', function () {
    $result = runAdmin(['email' => 'Ops@Example.com']);

    $user = User::query()->findOrFail($result['user_id']);

    expect($result['email'])->toBe('ops@example.com')
        ->and($result['password'])->toHaveLength(24)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->belongsToOrganization($result['organization_id']))->toBeTrue();
});

it('issues a working full-access API token pinned to the organization', function () {
    $result = runAdmin(['email' => 'ops@example.com', '--token' => 'cli']);

    $this->withToken($result['token'])->getJson('/api/v1/me')->assertOk();
});

it('is idempotent and can own a named organization', function () {
    $first = runAdmin(['email' => 'ops@example.com', '--organization' => 'Acme']);
    $second = runAdmin(['email' => 'ops@example.com', '--organization' => 'Acme']);

    expect($second['user_id'])->toBe($first['user_id'])
        ->and($second['organization_id'])->toBe($first['organization_id'])
        ->and($second)->not->toHaveKey('password')
        ->and(User::query()->count())->toBe(1);
});

it('rejects an invalid e-mail', function () {
    expect(Artisan::call('kiln:admin', ['email' => 'nope']))->toBe(2);
});

it('resets the password of an existing admin only when asked', function () {
    $first = runAdmin(['email' => 'ops@example.com']);
    $user = User::query()->findOrFail($first['user_id']);

    $reset = runAdmin(['email' => 'ops@example.com', '--reset-password' => true, '--password' => 'correct horse battery']);

    expect($reset['password'])->toBe('correct horse battery')
        ->and(Hash::check('correct horse battery', $user->refresh()->password))->toBeTrue()
        ->and(runAdmin(['email' => 'ops@example.com', '--password' => 'ignored']))->not->toHaveKey('password')
        ->and(Hash::check('correct horse battery', $user->refresh()->password))->toBeTrue();
});
