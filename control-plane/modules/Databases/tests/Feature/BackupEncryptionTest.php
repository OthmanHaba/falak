<?php

use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Databases\Domain\Models\Restore;
use Falak\Identity\Contracts\Role;
use Falak\Kernel\Security\BackupKeys;
use Falak\Kernel\Security\DecryptionFailed;
use Falak\Kernel\Security\Sealer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

// A syntactically valid recipient / identity (age's bech32 alphabet), built so no secret-shaped literal sits here.
const ENC_RECIPIENT = 'age1ql3z7hjy54pw3hyww5ayyfg7zqgvc7w3j2elw8zmrj2kg5sfn9aqmcac8p';

function enc_identity(): string
{
    return 'AGE-SECRET-KEY-1'.str_repeat('Q', 58);
}

beforeEach(function () {
    databases_fake_dns();
    Carbon::setTestNow('2026-10-07 02:59:30');
    $this->agents = FakeAgentGateway::install();
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
    $this->engine = databases_instance($this->organization, 'postgresql');
    $this->db = databases_active_db($this->engine, 'shop');
    $this->provider = databases_provider($this->organization);
});

afterEach(fn () => Carbon::setTestNow());

/** Run a backup (of a schedule when given) and let the agent succeed with its key. */
function enc_backup(object $test, ?BackupSchedule $schedule = null): Backup
{
    $schedule !== null
        ? $test->post("/databases/schedules/{$schedule->id}/run")->assertSessionHasNoErrors()
        : $test->post("/databases/databases/{$test->db->id}/backups", ['storage_provider_id' => $test->provider->id])->assertSessionHasNoErrors();
    $command = $test->agents->last('db.backup');
    // Kept before the command settles (the payload forgets it then).
    $test->lastKey = isset($command['payload']['encryption']['key']) ? base64_decode($command['payload']['encryption']['key'], true) : null;
    $test->agents->succeed($command['handle'], [
        'size_bytes' => 100, 'sha256' => str_repeat('a', 64), 'location' => 'x', 'plaintext_sha256' => str_repeat('b', 64),
        'encryption' => $command['payload']['encryption']['mode'], 'key_id' => $command['payload']['encryption']['key_id'], 'cipher' => 'aes-256-gcm', 'compression' => 'zstd',
        'table_counts' => ['public.orders' => 1000, 'public.users' => 10],
    ]);

    return Backup::query()->latest('id')->firstOrFail();
}

function enc_schedule(object $test, array $attributes = []): BackupSchedule
{
    $test->post("/databases/instances/{$test->engine->id}/schedules", [
        'name' => 'Nightly', 'storage_provider_id' => $test->provider->id, 'database_ids' => [$test->db->id], 'cron' => '0 3 * * *', ...$attributes,
    ])->assertSessionHasNoErrors();

    return BackupSchedule::query()->latest('id')->firstOrFail();
}

it('wraps each backup key under the organization key, bound to the backup', function () {
    $backup = enc_backup($this);
    $raw = $this->lastKey;
    $keys = app(BackupKeys::class);

    expect(strlen((string) $raw))->toBe(32)
        ->and($backup->wrapped_key)->toStartWith(Sealer::PREFIX)
        ->and($backup->wrapped_key)->not->toContain(base64_encode($raw))
        ->and($keys->unwrap($backup->wrapped_key, $this->organization->id, $backup->id))->toBe($raw)
        ->and($backup->toArray())->not->toHaveKey('wrapped_key');

    // The AAD binds it to this backup and organization: moved to another row or organization, it doesn't open.
    expect(fn () => $keys->unwrap($backup->wrapped_key, $this->organization->id, strtolower((string) Str::ulid())))->toThrow(DecryptionFailed::class);
    [, $other] = memberOf();
    expect(fn () => $keys->unwrap($backup->wrapped_key, $other->id, $backup->id))->toThrow(DecryptionFailed::class);

    // A second backup has another key.
    $second = enc_backup($this);
    expect($this->lastKey)->not->toBe($raw)
        ->and($second->wrapped_key)->not->toBe($backup->wrapped_key);
});

it('forgets the key in the stored payload once the command settled', function () {
    $this->post("/databases/databases/{$this->db->id}/backups", ['storage_provider_id' => $this->provider->id]);
    $command = $this->agents->last('db.backup');
    $key = $command['payload']['encryption']['key'];
    $this->agents->succeed($command['handle'], [
        'size_bytes' => 1, 'sha256' => str_repeat('a', 64), 'location' => 'x', 'plaintext_sha256' => str_repeat('b', 64),
        'encryption' => 'cp', 'key_id' => $command['payload']['encryption']['key_id'], 'cipher' => 'aes-256-gcm', 'compression' => 'zstd',
    ]);

    expect($this->agents->commands[$command['handle']->id]['payload']['encryption']['key'])->toBe('[forgotten]')
        ->and(json_encode($this->agents->commands[$command['handle']->id]['payload']))->not->toContain($key);
});

it('fails a backup the agent did not encrypt with its key', function () {
    $this->post("/databases/databases/{$this->db->id}/backups", ['storage_provider_id' => $this->provider->id]);
    $command = $this->agents->last('db.backup');
    $this->agents->succeed($command['handle'], ['size_bytes' => 1, 'sha256' => str_repeat('a', 64), 'location' => 'x', 'plaintext_sha256' => str_repeat('b', 64),
        'encryption' => 'cp', 'key_id' => 'another-backup', 'cipher' => 'aes-256-gcm', 'compression' => 'zstd']);

    expect(Backup::query()->sole())->status->value->toBe('failed')->error->toContain('encrypted')
        ->and(Backup::query()->sole()->isRestorable())->toBeFalse();
});

it('keeps customer-held keys out of the control plane', function () {
    $schedule = enc_schedule($this, ['encryption_mode' => 'customer', 'age_recipient' => ENC_RECIPIENT]);
    $backup = enc_backup($this, $schedule);
    $command = $this->agents->last('db.backup');

    expect($command['payload']['encryption'])->toBe(['mode' => 'age', 'key_id' => $backup->id, 'recipient' => ENC_RECIPIENT])
        ->and($backup)->encryption_mode->toBe('customer')->wrapped_key->toBeNull()->age_recipient->toBe(ENC_RECIPIENT)
        ->and($backup->isRestorable())->toBeTrue();

    // A restore needs the identity; it is sent once, then forgotten, and stored nowhere.
    databases_active_db($this->engine, 'shop_copy');
    $this->post("/databases/backups/{$backup->id}/restore", ['database_instance_id' => $this->engine->id, 'database' => 'shop_copy', 'confirm' => 'shop_copy'])
        ->assertSessionHasErrors('identity');
    $this->post("/databases/backups/{$backup->id}/restore", ['database_instance_id' => $this->engine->id, 'database' => 'shop_copy', 'confirm' => 'shop_copy', 'identity' => 'AGE-SECRET-KEY-1nope'])
        ->assertSessionHasErrors('identity');
    $this->post("/databases/backups/{$backup->id}/restore", ['database_instance_id' => $this->engine->id, 'database' => 'shop_copy', 'confirm' => 'shop_copy', 'identity' => enc_identity()])
        ->assertSessionHasNoErrors();

    $restore = $this->agents->last('db.restore');
    expect($restore['payload']['encryption'])->toBe(['mode' => 'age', 'key_id' => $backup->id, 'identity' => enc_identity()])
        ->and(databases_schema_errors($restore))->toBe([]);
    $this->agents->succeed($restore['handle'], ['bytes' => 10]);

    expect($this->agents->commands[$restore['handle']->id]['payload']['encryption']['identity'])->toBe('[forgotten]');
    foreach (['databases_backups', 'databases_restores', 'databases_backup_schedules', 'identity_audit_log'] as $table) {
        expect(json_encode(DB::table($table)->get()))->not->toContain(str_repeat('Q', 58));
    }
    expect(Restore::query()->sole()->status->value)->toBe('succeeded');

    // There is no key to export.
    $this->withSession(['identity.reauthenticated_at' => time()])->postJson("/databases/backups/{$backup->id}/key")->assertUnprocessable();
});

it('validates the key mode and the recipient', function () {
    $this->post("/databases/instances/{$this->engine->id}/schedules", ['name' => 'N', 'storage_provider_id' => $this->provider->id, 'database_ids' => [$this->db->id], 'cron' => '0 3 * * *', 'encryption_mode' => 'customer'])
        ->assertSessionHasErrors('age_recipient');
    $this->post("/databases/instances/{$this->engine->id}/schedules", ['name' => 'N', 'storage_provider_id' => $this->provider->id, 'database_ids' => [$this->db->id], 'cron' => '0 3 * * *', 'encryption_mode' => 'customer', 'age_recipient' => 'ssh-ed25519 AAAAC3Nz'])
        ->assertSessionHasErrors('age_recipient');
    $this->post("/databases/instances/{$this->engine->id}/schedules", ['name' => 'N', 'storage_provider_id' => $this->provider->id, 'database_ids' => [$this->db->id], 'cron' => '0 3 * * *', 'encryption_mode' => 'none'])
        ->assertSessionHasErrors('encryption_mode');
    $this->post("/databases/instances/{$this->engine->id}/schedules", ['name' => 'N', 'storage_provider_id' => $this->provider->id, 'database_ids' => [$this->db->id], 'cron' => '0 3 * * *', 'compression' => 'none'])
        ->assertSessionHasNoErrors();

    expect(BackupSchedule::query()->sole()->encryption_mode)->toBe('cp');
});

it('exports a cp key only after re-authentication, to restorers, audited', function () {
    $backup = enc_backup($this);
    $raw = (string) $this->lastKey;
    expect(strlen($raw))->toBe(32);

    $this->postJson("/databases/backups/{$backup->id}/key")->assertStatus(423);
    $this->assertDatabaseMissing('identity_audit_log', ['action' => 'databases.backup_key_exported']);

    $response = $this->withSession(['identity.reauthenticated_at' => time()])->postJson("/databases/backups/{$backup->id}/key")->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');
    expect($response->json('content'))->toContain(bin2hex($raw))->toStartWith('# Falak backup key')
        ->and($response->json('key_id'))->toBe($backup->id);
    $this->assertDatabaseHas('identity_audit_log', ['action' => 'databases.backup_key_exported', 'subject_id' => $backup->id]);

    // Developers may back up but not export keys (they open the data).
    [$developer] = memberOf($this->organization, Role::Developer);
    $this->actingAs($developer)->withSession(['identity.reauthenticated_at' => time()])->postJson("/databases/backups/{$backup->id}/key")->assertForbidden();

    // Another organization's member never reaches it.
    [$stranger] = memberOf();
    $this->actingAs($stranger)->withSession(['identity.reauthenticated_at' => time()])->postJson("/databases/backups/{$backup->id}/key")->assertNotFound();
});

it('refuses to restore backups taken before encryption', function () {
    $backup = enc_backup($this);
    $backup->forceFill(['encryption_mode' => null, 'wrapped_key' => null])->save();
    databases_active_db($this->engine, 'shop_copy');

    $this->post("/databases/backups/{$backup->id}/restore", ['database_instance_id' => $this->engine->id, 'database' => 'shop_copy', 'confirm' => 'shop_copy'])
        ->assertSessionHasErrors('backup');
});
