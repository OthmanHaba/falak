<?php

namespace Falak\Kernel\Security\Kek;

use Falak\Kernel\Security\DecryptionFailed;
use Falak\Kernel\Security\KeyEncryptionKey;
use Falak\Kernel\Security\KeyUnavailable;
use Falak\Kernel\Support\Aws\SigV4Signer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * KEK held by AWS KMS (symmetric key): data keys are wrapped with KMS Encrypt / Decrypt, so the KEK never
 * leaves KMS. The context goes in as the KMS EncryptionContext (logged by CloudTrail, checked on decrypt).
 *
 * @see https://docs.aws.amazon.com/kms/latest/APIReference/API_Encrypt.html
 */
final class AwsKmsKek implements KeyEncryptionKey
{
    public const PROVIDER = 'aws-kms';

    private const PREFIX = 'kms1:';

    public function __construct(
        private readonly string $keyId,
        private readonly string $region,
        private readonly string $accessKeyId,
        #[\SensitiveParameter] private readonly string $secretAccessKey,
        #[\SensitiveParameter] private readonly ?string $sessionToken = null,
        private readonly ?string $endpoint = null,
    ) {
        if ($keyId === '' || $region === '' || $accessKeyId === '' || $secretAccessKey === '') {
            throw new KeyUnavailable('The aws-kms KEK provider needs FALAK_KEK_AWS_KMS_KEY_ID, FALAK_KEK_AWS_REGION, FALAK_KEK_AWS_ACCESS_KEY_ID and FALAK_KEK_AWS_SECRET_ACCESS_KEY.');
        }
    }

    public function provider(): string
    {
        return self::PROVIDER;
    }

    public function id(): string
    {
        return $this->keyId;
    }

    public function wrap(#[\SensitiveParameter] string $dataKey, array $context): string
    {
        $json = $this->call('Encrypt', [
            'KeyId' => $this->keyId,
            'Plaintext' => base64_encode($dataKey),
            'EncryptionContext' => (object) $context,
        ]);

        $blob = $json['CiphertextBlob'] ?? null;

        if (! is_string($blob) || $blob === '') {
            throw new KeyUnavailable('AWS KMS Encrypt returned no CiphertextBlob.');
        }

        return self::PREFIX.$blob;
    }

    public function unwrap(string $wrapped, array $context): string
    {
        if (! str_starts_with($wrapped, self::PREFIX)) {
            throw new DecryptionFailed('The data key was not wrapped by AWS KMS.');
        }

        $json = $this->call('Decrypt', [
            'KeyId' => $this->keyId,
            'CiphertextBlob' => substr($wrapped, strlen(self::PREFIX)),
            'EncryptionContext' => (object) $context,
        ]);

        $plaintext = base64_decode((string) ($json['Plaintext'] ?? ''), true);

        if ($plaintext === false || $plaintext === '') {
            throw new KeyUnavailable('AWS KMS Decrypt returned no Plaintext.');
        }

        return $plaintext;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function call(string $action, array $payload): array
    {
        $url = rtrim($this->endpoint ?: "https://kms.{$this->region}.amazonaws.com", '/').'/';
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);

        $headers = (new SigV4Signer($this->accessKeyId, $this->secretAccessKey, $this->region, 'kms', $this->sessionToken, s3: false))
            ->signHeaders('POST', $url, [
                'Content-Type' => 'application/x-amz-json-1.1',
                'X-Amz-Target' => "TrentService.{$action}",
            ], hash('sha256', $body));

        try {
            $response = Http::timeout(15)->connectTimeout(5)->retry(2, 200, fn ($e) => self::transient($e), throw: false)
                ->withHeaders($headers)
                ->withBody($body, 'application/x-amz-json-1.1')
                ->post($url);
        } catch (ConnectionException $e) {
            throw new KeyUnavailable("AWS KMS ({$url}) is unreachable: {$e->getMessage()}", previous: $e);
        }

        if (! $response->successful()) {
            $this->fail($action, $response);
        }

        return (array) $response->json();
    }

    /** Retry connection errors and 5xx only; a refused or invalid request fails the same way again. */
    public static function transient(\Throwable $e): bool
    {
        return $e instanceof ConnectionException || ($e instanceof RequestException && $e->response->serverError());
    }

    private function fail(string $action, Response $response): never
    {
        $type = (string) ($response->json('__type') ?? 'error');
        $message = (string) ($response->json('message') ?? $response->json('Message') ?? '');
        $type = str_contains($type, '#') ? substr($type, strrpos($type, '#') + 1) : $type;

        // InvalidCiphertextException: wrong key, context or blob; the rest is configuration or access.
        if ($type === 'InvalidCiphertextException') {
            throw new DecryptionFailed("AWS KMS {$action}: the wrapped data key does not match this KMS key or context.");
        }

        throw new KeyUnavailable(trim("AWS KMS {$action} failed (HTTP {$response->status()}, {$type}) {$message}"));
    }
}
