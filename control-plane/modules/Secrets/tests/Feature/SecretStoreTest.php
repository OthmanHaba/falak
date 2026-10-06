<?php

use Falak\Identity\Application\Actions\DeleteOrganization;
use Falak\Identity\Events\OrganizationDeleted;
use Falak\Kernel\Security\DataKey;
use Falak\Kernel\Security\DecryptionFailed;
use Falak\Secrets\Application\Actions\DisableSecretVersion;
use Falak\Secrets\Application\Actions\RollBackSecret;
use Falak\Secrets\Application\Actions\SetSecretValue;
use Falak\Secrets\Application\Jobs\PruneAccessLog;
use Falak\Secrets\Application\SecretCipher;
use Falak\Secrets\Contracts\AccessorType;
use Falak\Secrets\Contracts\Data\ScopeChain;
use Falak\Secrets\Contracts\Data\SecretAccessor;
use Falak\Secrets\Contracts\Secrets;
use Falak\Secrets\Contracts\SecretScope;
use Falak\Secrets\Domain\Models\AccessLogEntry;
use Falak\Secrets\Domain\Models\Secret;
use Falak\Secrets\Domain\Models\SecretVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = memberOf();
    $this->environment = projects_default_env($this->organization);
    $this->site = projects_site($this->organization, 'web', [], $this->environment);
    $this->serviceId = secrets_service_of($this->site);
    $this->chain = new ScopeChain($this->organization->id, $this->environment->project_id, $this->environment->id, $this->serviceId);
});

it('seals each version under the organization data key, bound to its secret and version', function () {
    $secret = secrets_create($this->organization, 'API_KEY', 'v1-value');
    $version = SecretVersion::query()->where('secret_id', $secret->id)->sole();

    expect($version->ciphertext)->toStartWith('fk1:')->not->toContain('v1-value')
        ->and(app(SecretCipher::class)->open($secret, $version))->toBe('v1-value')
        ->and(DataKey::query()->where('purpose', DataKey::organization($this->organization->id))->count())->toBe(1);

    // The same ciphertext as another secret of the organization, another version, or another organization's secret.
    $other = secrets_create($this->organization, 'OTHER', 'x');
    DB::table('secrets_versions')->where('secret_id', $other->id)->update(['ciphertext' => $version->ciphertext]);
    expect(fn () => app(SecretCipher::class)->open($other, SecretVersion::query()->where('secret_id', $other->id)->sole()))->toThrow(DecryptionFailed::class);

    app(SetSecretValue::class)($secret, 'v2-value', null);
    DB::table('secrets_versions')->where('secret_id', $secret->id)->where('version', 2)->update(['ciphertext' => $version->ciphertext]);
    expect(fn () => app(SecretCipher::class)->open($secret, SecretVersion::query()->where('secret_id', $secret->id)->where('version', 2)->sole()))->toThrow(DecryptionFailed::class);

    [, $stranger] = memberOf();
    $foreign = secrets_create($stranger, 'API_KEY', 'theirs');
    DB::table('secrets_versions')->where('secret_id', $foreign->id)->update(['ciphertext' => $version->ciphertext]);
    // Even with the AAD of the original row, the envelope is not under the other organization's key.
    $forged = new SecretVersion(['version' => 1, 'ciphertext' => $version->ciphertext]);
    $impostor = (new Secret)->forceFill(['id' => $secret->id, 'organization_id' => $stranger->id]);
    expect(fn () => app(SecretCipher::class)->open($impostor, $forged))->toThrow(DecryptionFailed::class)
        ->and(app(Secrets::class)->resolve(new ScopeChain($stranger->id), ['API_KEY'])->errors['API_KEY'])->toContain('could not be decrypted');
});

it('resolves the nearest scope: service, then environment, project and organization', function () {
    $store = app(Secrets::class);

    secrets_create($this->organization, 'TOKEN', 'org');
    expect($store->resolve($this->chain, ['TOKEN'])->values['TOKEN'])->toBe('org');

    secrets_create($this->organization, 'TOKEN', 'project', SecretScope::Project, $this->environment->project_id);
    expect($store->resolve($this->chain, ['TOKEN'])->values['TOKEN'])->toBe('project');

    secrets_create($this->organization, 'TOKEN', 'environment', SecretScope::Environment, $this->environment->id);
    expect($store->resolve($this->chain, ['TOKEN'])->values['TOKEN'])->toBe('environment');

    secrets_create($this->organization, 'TOKEN', 'service', SecretScope::Service, $this->serviceId);
    expect($store->resolve($this->chain, ['TOKEN'])->values['TOKEN'])->toBe('service');

    // Another environment of the project sees the project's value; another organization sees nothing.
    $staging = projects_environment($this->organization);
    expect($store->resolve(new ScopeChain($this->organization->id, $staging->project_id, $staging->id), ['TOKEN'])->values['TOKEN'])->toBe('project');
    [, $stranger] = memberOf();
    expect($store->resolve(new ScopeChain($stranger->id), ['TOKEN'])->errors['TOKEN'])->toContain('secret TOKEN is not defined');
});

it('rejects scopes of another organization and duplicate names', function () {
    [, $stranger] = memberOf();

    expect(fn () => secrets_create($this->organization, 'A', 'x', SecretScope::Project, projects_default_env($stranger)->project_id))->toThrow(ValidationException::class);
    secrets_create($this->organization, 'A', 'x');
    expect(fn () => secrets_create($this->organization, 'A', 'y'))->toThrow(ValidationException::class);
});

it('logs every read with its accessor, once per version for a deployment, and nothing for a check', function () {
    $secret = secrets_create($this->organization, 'DB_PASS', 'hunter2', attributes: ['sensitive' => true]);
    $store = app(Secrets::class);

    $checked = $store->check($this->chain, ['DB_PASS', 'NOPE']);
    expect($checked->values)->toBe([])->and($checked->sensitive)->toBe(['DB_PASS'])->and(array_keys($checked->errors))->toBe(['NOPE'])
        ->and(AccessLogEntry::query()->count())->toBe(0);

    $store->accessedAs(SecretAccessor::deployment('01k6deploy0000000000000000', 7), function () use ($store) {
        $store->resolve($this->chain, ['DB_PASS']);
        $store->resolve($this->chain, ['DB_PASS']);
    });
    $store->resolve($this->chain, ['DB_PASS'], SecretAccessor::system('Backfill'));

    $log = AccessLogEntry::query()->orderBy('id')->get();
    expect($log)->toHaveCount(2)
        ->and($log[0]->actor_type)->toBe(AccessorType::Deployment)
        ->and($log[0]->reason)->toBe('Deployment #7')
        ->and($log[0]->secret_name)->toBe('DB_PASS')
        ->and($log[1]->actor_type)->toBe(AccessorType::System)
        ->and($secret->refresh()->last_accessed_at)->not->toBeNull();
});

it('rolls back by creating a new version, and disables old versions only', function () {
    $secret = secrets_create($this->organization, 'KEY', 'one');
    app(SetSecretValue::class)($secret, 'two', null);

    $restored = app(RollBackSecret::class)($secret->refresh(), 1, $this->user->id);

    expect($restored->version)->toBe(3)->and($restored->restored_from)->toBe(1)
        ->and($secret->refresh()->current_version)->toBe(3)
        ->and(SecretVersion::query()->where('secret_id', $secret->id)->count())->toBe(3)
        ->and(app(Secrets::class)->resolve(new ScopeChain($this->organization->id), ['KEY'])->values['KEY'])->toBe('one');

    expect(fn () => app(DisableSecretVersion::class)($secret, 3))->toThrow(ValidationException::class);
    app(DisableSecretVersion::class)($secret, 2);
    expect(fn () => app(RollBackSecret::class)($secret, 2, null))->toThrow(ValidationException::class);
});

it('disables a version with its rollback copies, and not while the current version is one', function () {
    $secret = secrets_create($this->organization, 'KEY', 'leaked');            // v1
    app(SetSecretValue::class)($secret, 'two', null);                          // v2
    app(RollBackSecret::class)($secret->refresh(), 1, null);                   // v3 = copy of v1
    app(RollBackSecret::class)($secret->refresh(), 2, null);                   // v4 = copy of v2
    app(RollBackSecret::class)($secret->refresh(), 3, null);                   // v5 = copy of v3 (so of v1), current

    expect(fn () => app(DisableSecretVersion::class)($secret->refresh(), 1))->toThrow(ValidationException::class, 'rollback copy of v1');

    app(SetSecretValue::class)($secret->refresh(), 'fresh', null);             // v6
    expect(app(DisableSecretVersion::class)($secret->refresh(), 1))->toBe([1, 3, 5]);

    $disabled = SecretVersion::query()->where('secret_id', $secret->id)->whereNotNull('disabled_at')->orderBy('version')->pluck('version')->all();
    expect($disabled)->toBe([1, 3, 5])
        ->and(fn () => app(RollBackSecret::class)($secret->refresh(), 5, null))->toThrow(ValidationException::class);
});

it('refuses a linked secret until a provider of its type is configured', function () {
    expect(fn () => secrets_create($this->organization, 'VAULTED', '', attributes: ['kind' => 'linked', 'reference' => 'vault://kv/data/app#DB_PASS']))
        ->toThrow(ValidationException::class, 'Add a HashiCorp Vault / OpenBao provider first');
});

it('deletes the secrets, access log and data key of a deleted organization', function () {
    secrets_create($this->organization, 'GONE', 'x');
    app(Secrets::class)->resolve(new ScopeChain($this->organization->id), ['GONE']);
    [, $other] = memberOf();
    secrets_create($other, 'KEPT', 'y');

    event(new OrganizationDeleted($this->organization->id));

    expect(Secret::query()->pluck('name')->all())->toBe(['KEPT'])
        ->and(SecretVersion::query()->count())->toBe(1)
        ->and(AccessLogEntry::query()->count())->toBe(0)
        ->and(DataKey::query()->where('purpose', DataKey::organization($this->organization->id))->exists())->toBeFalse()
        ->and(DataKey::query()->where('purpose', DataKey::organization($other->id))->exists())->toBeTrue();
});

it('cleans up through the real organization deletion', function () {
    secrets_create($this->organization, 'X', 'y');

    app(DeleteOrganization::class)($this->organization, $this->user);

    expect(Secret::query()->where('organization_id', $this->organization->id)->exists())->toBeFalse()
        ->and(DataKey::query()->where('purpose', DataKey::organization($this->organization->id))->exists())->toBeFalse();
});

it('prunes the access log after its retention', function () {
    $secret = secrets_create($this->organization, 'OLD', 'x');
    app(Secrets::class)->resolve(new ScopeChain($this->organization->id), ['OLD']);
    AccessLogEntry::query()->update(['created_at' => now()->subDays(731)]);
    app(Secrets::class)->resolve(new ScopeChain($this->organization->id), ['OLD'], SecretAccessor::system('Recent'));

    (new PruneAccessLog)->handle();

    expect(AccessLogEntry::query()->pluck('reason')->all())->toBe(['Recent'])->and($secret->exists)->toBeTrue();
});
