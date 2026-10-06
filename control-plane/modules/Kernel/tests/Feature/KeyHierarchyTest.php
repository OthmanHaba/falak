<?php

use Falak\Kernel\Security\Casts\Sealed;
use Falak\Kernel\Security\Casts\SealedArray;
use Falak\Kernel\Security\DataKey;
use Falak\Kernel\Security\DecryptionFailed;
use Falak\Kernel\Security\Kek\LocalKek;
use Falak\Kernel\Security\KeyEncryptionKeys;
use Falak\Kernel\Security\KeyRing;
use Falak\Kernel\Security\KeyUnavailable;
use Falak\Kernel\Security\SealedColumns;
use Falak\Kernel\Security\Sealer;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SealedTestRecord extends Model
{
    use HasUlids;

    protected $table = 'kernel_sealed_test_records';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['token' => Sealed::class, 'other' => Sealed::class, 'env' => SealedArray::class];
    }
}

/** A KEK file for this test, made the current KEK (and optionally another as the previous one). */
function useKek(string $path, ?string $previous = null): void
{
    config(['kernel.keys.local.path' => $path, 'kernel.keys.local.previous_path' => $previous ?? '/nonexistent/kek.previous']);

    foreach ([KeyEncryptionKeys::class, KeyRing::class, Sealer::class] as $singleton) {
        app()->forgetInstance($singleton);
    }
}

function newKekFile(): string
{
    $path = sys_get_temp_dir().'/falak-kek-feature-'.bin2hex(random_bytes(6));
    file_put_contents($path, random_bytes(32));
    chmod($path, 0400);
    $GLOBALS['falakTestKekFiles'][] = $path;

    return $path;
}

function rawColumn(SealedTestRecord $record, string $column): ?string
{
    return DB::table('kernel_sealed_test_records')->where('id', $record->id)->value($column);
}

beforeEach(function () {
    $GLOBALS['falakTestKekFiles'] = [];

    Schema::create('kernel_sealed_test_records', function (Blueprint $table) {
        $table->ulid('id')->primary();
        $table->text('token')->nullable();
        $table->text('other')->nullable();
        $table->text('env')->nullable();
        $table->timestamps();
    });

    app()->instance(SealedColumns::class, new class extends SealedColumns
    {
        public function all(): array
        {
            return [
                ['table' => 'kernel_sealed_test_records', 'primary_key' => 'id', 'column' => 'env', 'aad' => 'kernel_sealed_test_records.env'],
                ['table' => 'kernel_sealed_test_records', 'primary_key' => 'id', 'column' => 'token', 'aad' => 'kernel_sealed_test_records.token'],
            ];
        }
    });
});

afterEach(function () {
    foreach ($GLOBALS['falakTestKekFiles'] as $file) {
        @unlink($file);
    }
});

it('seals cast attributes at rest under a lazily created platform data key', function () {
    expect(DataKey::query()->count())->toBe(0);

    $record = SealedTestRecord::create(['token' => 'tok_secret', 'env' => ['DB_PASSWORD' => 'p@ss', 'N' => 1]])->fresh();
    $key = DataKey::query()->sole();

    expect($record->token)->toBe('tok_secret')
        ->and($record->env)->toBe(['DB_PASSWORD' => 'p@ss', 'N' => 1])
        ->and(rawColumn($record, 'token'))->toStartWith("fk1:{$key->id}:")->not->toContain('tok_secret')
        ->and(rawColumn($record, 'env'))->not->toContain('p@ss')
        ->and($key->purpose)->toBe('platform')
        ->and($key->kek_provider)->toBe('local')
        ->and($key->kek_id)->toBe(app(KeyEncryptionKeys::class)->current()->id())
        ->and($key->wrapped_key)->toStartWith('lk1:')
        ->and($key->toArray())->not->toHaveKey('wrapped_key');

    SealedTestRecord::create(['token' => 'second']);
    expect(DataKey::query()->count())->toBe(1);
});

it('keeps null as null and does not dirty a model when the same value is assigned again', function () {
    $record = SealedTestRecord::create(['token' => null, 'other' => 'x', 'env' => ['a' => 1]])->fresh();

    expect(rawColumn($record, 'token'))->toBeNull()
        ->and($record->token)->toBeNull();

    $record->other = 'x';
    $record->env = ['a' => 1];
    expect($record->isDirty())->toBeFalse();

    $record->other = 'y';
    expect($record->isDirty('other'))->toBeTrue();
});

it('detects tampering with a stored value', function () {
    $record = SealedTestRecord::create(['token' => 'tok_secret']);
    $raw = rawColumn($record, 'token');
    $tampered = substr($raw, 0, -6).(substr($raw, -6, 1) === 'A' ? 'B' : 'A').substr($raw, -5);
    DB::table('kernel_sealed_test_records')->where('id', $record->id)->update(['token' => $tampered]);

    expect(fn () => $record->fresh()->token)->toThrow(DecryptionFailed::class);
});

it('binds a value to its column: a ciphertext copied to another column or table fails to open', function () {
    $record = SealedTestRecord::create(['token' => 'tok_secret']);
    $raw = rawColumn($record, 'token');

    DB::table('kernel_sealed_test_records')->where('id', $record->id)->update(['other' => $raw]);
    expect(fn () => $record->fresh()->other)->toThrow(DecryptionFailed::class);

    $sealer = app(Sealer::class);
    expect($sealer->open($raw, 'kernel_sealed_test_records.token'))->toBe('tok_secret')
        ->and(fn () => $sealer->open($raw, 'source_control_webhooks.token'))->toThrow(DecryptionFailed::class);
});

it('rejects malformed envelopes and unknown data keys', function (string $value) {
    expect(fn () => app(Sealer::class)->open($value, 'x'))->toThrow(DecryptionFailed::class);
})->with([
    'legacy Laravel ciphertext' => ['eyJpdiI6IjEyMyJ9'],
    'no key id' => ['fk1::abc'],
    'not base64' => ['fk1:01k6zzzzzzzzzzzzzzzzzzzzzz:@@@'],
    'unknown key' => ['fk1:01k6zzzzzzzzzzzzzzzzzzzzzz:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'],
]);

it('seals organization secrets under per-organization keys, bound to an arbitrary AAD', function () {
    $sealer = app(Sealer::class);
    $keys = app(KeyRing::class);
    $aad = Sealer::aad('secret', 'org_a', 'sec_1', '3');

    expect($keys->findOrganization('org_a'))->toBeNull();

    $sealed = $sealer->seal('s3cr3t', $aad, organizationId: 'org_a');
    $orgKey = $keys->findOrganization('org_a');

    expect($orgKey->purpose)->toBe('org:org_a')
        ->and(Sealer::keyId($sealed))->toBe($orgKey->id)
        ->and($keys->organization('org_a')->id)->toBe($orgKey->id)
        ->and($keys->organization('org_b')->id)->not->toBe($orgKey->id)
        ->and($sealer->open($sealed, $aad, organizationId: 'org_a'))->toBe('s3cr3t')
        ->and(fn () => $sealer->open($sealed, $aad, organizationId: 'org_b'))->toThrow(DecryptionFailed::class, "this organization's data key")
        ->and(fn () => $sealer->open($sealed, Sealer::aad('secret', 'org_a', 'sec_1', '4')))->toThrow(DecryptionFailed::class)
        ->and(Sealer::aad('ab', 'c'))->not->toBe(Sealer::aad('a', 'bc'));
});

it('refuses a wrapped data key moved to another purpose', function () {
    $keys = app(KeyRing::class);
    $platform = $keys->platform();
    $org = $keys->organization('org_a');
    DataKey::query()->whereKey($org->id)->update(['wrapped_key' => $platform->wrapped_key]);
    $keys->flush();

    expect(fn () => $keys->material($org->id))->toThrow(DecryptionFailed::class);
});

it('rotates the KEK: data keys are re-wrapped and every value stays readable', function () {
    useKek($old = newKekFile());
    $record = SealedTestRecord::create(['token' => 'tok_secret', 'env' => ['K' => 'v']]);
    app(Sealer::class)->seal('org value', 'aad', 'org_a');
    $before = rawColumn($record, 'token');

    useKek($new = newKekFile(), previous: $old);
    $this->artisan('falak:keys:rotate-kek')->assertSuccessful();

    $newId = app(KeyEncryptionKeys::class)->current()->id();
    expect(DataKey::query()->pluck('kek_id')->unique()->all())->toBe([$newId])
        ->and($newId)->not->toBe(LocalKek::fingerprint((string) file_get_contents($old)))
        ->and(rawColumn($record, 'token'))->toBe($before); // values are not re-encrypted

    // The previous KEK is no longer needed.
    useKek($new);
    expect($record->fresh()->token)->toBe('tok_secret')
        ->and($record->fresh()->env)->toBe(['K' => 'v']);

    $this->artisan('falak:keys:rotate-kek')->expectsOutputToContain('Re-wrapped')->assertSuccessful();
});

it('fails clearly when a data key is wrapped by a KEK it does not have', function () {
    useKek($old = newKekFile());
    SealedTestRecord::create(['token' => 'tok_secret']);

    useKek(newKekFile());
    expect(fn () => SealedTestRecord::query()->first()->token)
        ->toThrow(KeyUnavailable::class, 'neither FALAK_KEK_PATH nor FALAK_KEK_PREVIOUS_PATH');

    $this->artisan('falak:keys:rotate-kek')->assertFailed();
    $this->artisan('falak:keys:check')->assertFailed();
});

it('rotates the platform data key and re-encrypts every sealed column, resumably', function () {
    $records = collect(range(1, 5))->map(fn ($i) => SealedTestRecord::create(['token' => "tok_{$i}", 'env' => ['I' => $i]]));
    $old = app(KeyRing::class)->platform();
    config(['kernel.keys.rotate_batch' => 2]);

    $this->artisan('falak:keys:rotate-data')->assertSuccessful();

    $new = DataKey::query()->where('purpose', 'platform')->whereNull('retired_at')->sole();
    expect($new->id)->not->toBe($old->id)
        ->and($old->fresh()->retired_at)->not->toBeNull();

    foreach ($records as $i => $record) {
        expect(Sealer::keyId(rawColumn($record, 'token')))->toBe($new->id)
            ->and(Sealer::keyId(rawColumn($record, 'env')))->toBe($new->id)
            ->and($record->fresh()->token)->toBe('tok_'.($i + 1))
            ->and($record->fresh()->env)->toBe(['I' => $i + 1]);
    }

    // An interrupted rotation: one value still under the old key. --resume finishes without a new key.
    DB::table('kernel_sealed_test_records')->where('id', $records[0]->id)
        ->update(['token' => app(Sealer::class)->sealWith($old, 'tok_1', 'kernel_sealed_test_records.token')]);

    $this->artisan('falak:keys:rotate-data --resume')->assertSuccessful();

    expect(DataKey::query()->where('purpose', 'platform')->count())->toBe(2)
        ->and(Sealer::keyId(rawColumn($records[0], 'token')))->toBe($new->id)
        ->and($records[0]->fresh()->token)->toBe('tok_1');
});

it('checks the KEK and the data keys without printing secrets', function () {
    SealedTestRecord::create(['token' => 'tok_secret']);
    $kek = app(KeyEncryptionKeys::class)->current();

    $this->artisan('falak:keys:check')
        ->expectsOutputToContain("local {$kek->id()}")
        ->expectsOutputToContain('1 (0 wrapped by an earlier KEK)')
        ->assertSuccessful();

    $this->artisan('falak:keys:check --kek-only')->doesntExpectOutputToContain('Data keys')->assertSuccessful();

    useKek('/nonexistent/falak/kek');
    $this->artisan('falak:keys:check')->expectsOutputToContain('does not exist')->assertFailed();
});

it('generates a KEK file once and never overwrites it', function () {
    $path = sys_get_temp_dir().'/falak-kek-gen-'.bin2hex(random_bytes(6));
    $GLOBALS['falakTestKekFiles'][] = $path;

    $this->artisan('falak:keys:generate-kek', ['path' => $path])->assertSuccessful();
    $bytes = file_get_contents($path);

    expect(strlen($bytes))->toBe(32)
        ->and(fileperms($path) & 0777)->toBe(0400);

    $this->artisan('falak:keys:generate-kek', ['path' => $path])->expectsOutputToContain('already exists')->assertFailed();
    expect(file_get_contents($path))->toBe($bytes);
});
