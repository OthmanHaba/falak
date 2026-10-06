<?php

use Falak\Kernel\Security\Aead;
use Falak\Kernel\Security\DecryptionFailed;
use Falak\Kernel\Security\Kek\AwsKmsKek;
use Falak\Kernel\Security\Kek\LocalKek;
use Falak\Kernel\Security\Kek\VaultTransitKek;
use Falak\Kernel\Security\KeyUnavailable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function kekFile(?string $bytes = null, int $mode = 0400): string
{
    $path = sys_get_temp_dir().'/falak-kek-test-'.bin2hex(random_bytes(6));
    file_put_contents($path, $bytes ?? random_bytes(32));
    chmod($path, $mode);

    return $path;
}

describe('AES-256-GCM', function () {
    it('round-trips and authenticates the AAD', function () {
        $key = random_bytes(32);
        $sealed = Aead::seal($key, 'hunter2', 'ctx');

        expect(Aead::open($key, $sealed, 'ctx'))->toBe('hunter2')
            ->and(strlen($sealed))->toBe(12 + 7 + 16)
            ->and(Aead::seal($key, 'hunter2', 'ctx'))->not->toBe($sealed) // random nonce
            ->and(fn () => Aead::open($key, $sealed, 'other'))->toThrow(DecryptionFailed::class)
            ->and(fn () => Aead::open(random_bytes(32), $sealed, 'ctx'))->toThrow(DecryptionFailed::class);
    });

    it('detects a flipped byte anywhere in the ciphertext', function (int $offset) {
        $key = random_bytes(32);
        $sealed = Aead::seal($key, 'some secret value', '');
        $sealed[$offset] = chr(ord($sealed[$offset]) ^ 0x01);

        expect(fn () => Aead::open($key, $sealed, ''))->toThrow(DecryptionFailed::class);
    })->with([0, 11, 12, 20, 44]);
});

describe('local KEK', function () {
    it('wraps and unwraps bound to the context, with a stable fingerprint id', function () {
        $bytes = random_bytes(32);
        $kek = new LocalKek($path = kekFile($bytes));
        $wrapped = $kek->wrap($dataKey = random_bytes(32), ['falak:data-key' => 'a', 'falak:purpose' => 'platform']);

        expect($wrapped)->toStartWith('lk1:')->not->toContain(base64_encode($dataKey))
            ->and($kek->unwrap($wrapped, ['falak:purpose' => 'platform', 'falak:data-key' => 'a']))->toBe($dataKey)
            ->and(fn () => $kek->unwrap($wrapped, ['falak:data-key' => 'b', 'falak:purpose' => 'platform']))->toThrow(DecryptionFailed::class)
            ->and($kek->provider())->toBe('local')
            ->and($kek->id())->toBe(LocalKek::fingerprint($bytes))->toHaveLength(16)
            // falak-ctl computes the same id: sha256("falak-kek-id:" || key), first 16 hex digits
            ->and($kek->id())->toBe(substr(hash('sha256', 'falak-kek-id:'.$bytes), 0, 16));

        unlink($path);
    });

    it('refuses a missing file, a wrong size or loose permissions', function (?string $bytes, int $mode, string $message) {
        $path = $bytes === null ? sys_get_temp_dir().'/falak-kek-missing-'.bin2hex(random_bytes(4)) : kekFile($bytes, $mode);

        expect(fn () => (new LocalKek($path))->id())->toThrow(KeyUnavailable::class, $message);

        @unlink($path);
    })->with([
        'missing' => [null, 0400, 'does not exist'],
        'too short' => [str_repeat('a', 16), 0400, 'exactly 32 random bytes (it has 16)'],
        'too long (e.g. base64 text)' => [base64_encode(random_bytes(32))."\n", 0400, 'exactly 32 random bytes'],
        'group-readable' => [str_repeat('k', 32), 0640, 'has mode 0640'],
        'world-readable' => [str_repeat('k', 32), 0644, 'has mode 0644'],
    ]);

    it('accepts mode 0600', function () {
        expect((new LocalKek($path = kekFile(mode: 0600)))->id())->toHaveLength(16);
        unlink($path);
    });
});

describe('AWS KMS KEK', function () {
    beforeEach(fn () => $this->kek = new AwsKmsKek('alias/falak', 'eu-central-1', 'AKIDEXAMPLE', 'secret-key-example'));

    it('wraps with a SigV4-signed KMS Encrypt that carries the context', function () {
        Http::fake(['https://kms.eu-central-1.amazonaws.com/' => Http::response(['CiphertextBlob' => 'YmxvYg==', 'KeyId' => 'arn:aws:kms:eu-central-1:1:key/x'])]);

        expect($this->kek->wrap('0123456789abcdef0123456789abcdef', ['falak:purpose' => 'platform']))->toBe('kms1:YmxvYg==');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return $request->method() === 'POST'
                && $request->header('X-Amz-Target')[0] === 'TrentService.Encrypt'
                && $request->header('Content-Type')[0] === 'application/x-amz-json-1.1'
                && str_starts_with($request->header('Authorization')[0], 'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/')
                && str_contains($request->header('Authorization')[0], '/eu-central-1/kms/aws4_request')
                && $body['KeyId'] === 'alias/falak'
                && base64_decode($body['Plaintext']) === '0123456789abcdef0123456789abcdef'
                && $body['EncryptionContext'] === ['falak:purpose' => 'platform'];
        });
    });

    it('unwraps with KMS Decrypt', function () {
        Http::fake(['*' => Http::response(['Plaintext' => base64_encode('k'), 'KeyId' => 'arn'])]);

        expect($this->kek->unwrap('kms1:YmxvYg==', ['falak:purpose' => 'platform']))->toBe('k');

        Http::assertSent(fn (Request $request) => $request->header('X-Amz-Target')[0] === 'TrentService.Decrypt'
            && json_decode($request->body(), true)['CiphertextBlob'] === 'YmxvYg==');
    });

    it('maps KMS errors: a wrong context is a decryption failure, access problems make the key unavailable', function () {
        Http::fakeSequence()
            ->push(['__type' => 'InvalidCiphertextException'], 400)
            ->push(['__type' => 'com.amazonaws.kms#AccessDeniedException', 'message' => 'not allowed'], 400);

        expect(fn () => $this->kek->unwrap('kms1:YmxvYg==', []))->toThrow(DecryptionFailed::class)
            ->and(fn () => $this->kek->unwrap('kms1:YmxvYg==', []))->toThrow(KeyUnavailable::class, 'AccessDeniedException) not allowed');
    });

    it('needs its settings', function () {
        expect(fn () => new AwsKmsKek('', 'eu-central-1', 'a', 'b'))->toThrow(KeyUnavailable::class, 'FALAK_KEK_AWS_KMS_KEY_ID');
    });
});

describe('Vault transit KEK', function () {
    beforeEach(fn () => $this->kek = new VaultTransitKek('https://vault.example.com:8200', 'hvs.token', 'falak', 'transit', 'ops'));

    it('wraps and unwraps through transit/encrypt and transit/decrypt', function () {
        Http::fake([
            'https://vault.example.com:8200/v1/transit/encrypt/falak' => Http::response(['data' => ['ciphertext' => 'vault:v1:abc']]),
            'https://vault.example.com:8200/v1/transit/decrypt/falak' => Http::response(['data' => ['plaintext' => base64_encode('key')]]),
        ]);

        expect($wrapped = $this->kek->wrap('key', []))->toBe('vt1:vault:v1:abc')
            ->and($this->kek->unwrap($wrapped, []))->toBe('key')
            ->and($this->kek->id())->toBe('transit/falak');

        Http::assertSent(fn (Request $request) => $request->header('X-Vault-Token')[0] === 'hvs.token'
            && $request->header('X-Vault-Namespace')[0] === 'ops'
            && str_ends_with($request->url(), '/decrypt/falak') && $request['ciphertext'] === 'vault:v1:abc');
    });

    it('maps a refused decrypt to a decryption failure and other errors to an unavailable key', function () {
        Http::fakeSequence()
            ->push(['errors' => ['cipher: message authentication failed']], 400)
            ->push(['errors' => ['permission denied']], 403);

        expect(fn () => $this->kek->unwrap('vt1:vault:v1:abc', []))->toThrow(DecryptionFailed::class, 'message authentication failed')
            ->and(fn () => $this->kek->wrap('key', []))->toThrow(KeyUnavailable::class, 'permission denied');
    });
});
