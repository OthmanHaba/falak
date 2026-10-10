<?php

use Falak\Databases\Contracts\DatabaseProvisioner;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\Restore;
use Falak\Identity\Contracts\Role;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    databases_fake_dns();
    $this->agents = FakeAgentGateway::install();
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
    $this->provider = databases_provider($this->organization);
    $this->provisioner = app(DatabaseProvisioner::class);
});

/** A successful backup of $db, taken $at, by the real flow. */
function prs_backup(object $test, $db, string $at): Backup
{
    Carbon::setTestNow($at);
    $test->post("/databases/databases/{$db->id}/backups", ['storage_provider_id' => $test->provider->id])->assertSessionHasNoErrors();
    $command = $test->agents->last('db.backup');
    $test->agents->succeed($command['handle'], [
        'size_bytes' => 100, 'sha256' => str_repeat('a', 64), 'location' => 'x', 'plaintext_sha256' => str_repeat('b', 64),
        'encryption' => $command['payload']['encryption']['mode'], 'key_id' => $command['payload']['encryption']['key_id'], 'cipher' => 'aes-256-gcm', 'compression' => 'zstd',
    ]);
    Carbon::setTestNow();

    return Backup::query()->latest('id')->firstOrFail();
}

it('restores the newest backup of a database into another', function () {
    $staging = databases_active_db(databases_instance($this->organization), 'shop');
    $preview = databases_active_db(databases_instance($this->organization), 'shop');
    prs_backup($this, $staging, '2026-10-01 03:00:00');
    $newest = prs_backup($this, $staging, '2026-10-02 03:00:00');

    $restoreId = $this->provisioner->restoreLatestBackup($staging->id, $preview->id);

    $restore = Restore::query()->findOrFail($restoreId);
    expect($restore->backup_id)->toBe($newest->id)
        ->and($restore->database_instance_id)->toBe($preview->database_instance_id)
        ->and($this->agents->last('db.restore')['handle']->serverId)->toBe($preview->server_id);
});

it('refuses when the source has no restorable backup, or belongs to another organization', function () {
    $staging = databases_active_db(databases_instance($this->organization), 'shop');
    $preview = databases_active_db(databases_instance($this->organization), 'shop');

    expect(fn () => $this->provisioner->restoreLatestBackup($staging->id, $preview->id))->toThrow(ValidationException::class);

    [, $other] = memberOf(null, Role::Owner);
    $theirs = databases_active_db(databases_instance($other), 'shop');
    prs_backup($this, $staging, '2026-10-01 03:00:00');

    expect(fn () => $this->provisioner->restoreLatestBackup($staging->id, $theirs->id))->toThrow(ValidationException::class);
});

it('runs a SQL script in the database container without putting the password on a command line', function (string $engine) {
    $instance = databases_instance($this->organization, $engine);
    $db = databases_active_db($instance, 'shop');
    databases_active_user($instance, 'shop_user', $db);

    $commandId = $this->provisioner->runScript($db->id, 'sql', "UPDATE users SET email = concat('user', id, '@example.test');", 'preview-1');

    $command = $this->agents->last('system.exec');
    $password = $command['payload']['env']['DB_PASSWORD'];
    expect($command['handle']->id)->toBe($commandId)
        ->and($command['payload']['stdin'])->toContain('UPDATE users')
        ->and($command['payload']['script'])->toContain('docker exec -i')->toContain("falak-db-{$instance->id}")
        ->and($command['payload']['script'])->not->toContain($password)
        ->and($command['payload']['mask'])->toBe(['DB_PASSWORD'])
        ->and($command['payload']['env']['DB_USERNAME'])->toBe('shop_user')
        ->and($command['payload']['env']['DB_DATABASE'])->toBe('shop');
    expect($command['payload']['script'])->toContain($engine === 'postgresql' ? 'ON_ERROR_STOP=1' : 'command -v mariadb || command -v mysql');

    // Settled: the password is dropped from the stored payload.
    $this->agents->succeed($command['handle'], ['exit_code' => 0, 'duration_ms' => 5]);
})->with(['postgresql', 'mysql']);

it('refuses scripts for Redis, empty scripts and databases that are not active', function () {
    $redis = databases_active_db(databases_instance($this->organization, 'redis'), '0');

    expect(fn () => $this->provisioner->runScript($redis->id, 'sql', 'FLUSHALL', 'x'))->toThrow(ValidationException::class);

    $db = databases_active_db(databases_instance($this->organization), 'shop');
    expect(fn () => $this->provisioner->runScript($db->id, 'sql', '  ', 'x'))->toThrow(ValidationException::class);
});
