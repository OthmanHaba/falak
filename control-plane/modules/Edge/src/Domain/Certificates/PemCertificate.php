<?php

namespace Falak\Edge\Domain\Certificates;

use DateTimeImmutable;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * A validated PEM certificate + matching private key (+ optional intermediate chain).
 */
final readonly class PemCertificate
{
    /**
     * @param  list<string>  $domains  lowercase SANs (falls back to the CN)
     */
    public function __construct(
        public string $certPem,
        public string $keyPem,
        public ?string $chainPem,
        public array $domains,
        public ?string $issuer,
        public DateTimeImmutable $notBefore,
        public DateTimeImmutable $notAfter,
        public string $fingerprint,
    ) {}

    /**
     * @throws InvalidArgumentException with a user-facing message
     */
    public static function parse(string $certPem, #[SensitiveParameter] string $keyPem, ?string $chainPem = null): self
    {
        $certPem = self::firstBlock(trim($certPem), 'CERTIFICATE')
            ?? throw new InvalidArgumentException('The certificate must be PEM encoded (-----BEGIN CERTIFICATE-----).');

        $info = @openssl_x509_parse($certPem);

        if (! is_array($info)) {
            throw new InvalidArgumentException('The certificate could not be parsed.');
        }

        if (preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----/', $keyPem) !== 1) {
            throw new InvalidArgumentException('The private key must be PEM encoded (-----BEGIN PRIVATE KEY-----).');
        }

        $key = @openssl_pkey_get_private(trim($keyPem));

        if ($key === false) {
            throw new InvalidArgumentException('The private key could not be read (encrypted keys are not supported).');
        }

        if (! @openssl_x509_check_private_key($certPem, $key)) {
            throw new InvalidArgumentException('The private key does not match the certificate.');
        }

        $chain = $chainPem !== null && trim($chainPem) !== '' ? trim($chainPem) : null;

        if ($chain !== null) {
            preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $chain, $blocks);

            if ($blocks[0] === []) {
                throw new InvalidArgumentException('The chain must contain PEM certificates.');
            }

            foreach ($blocks[0] as $block) {
                if (! is_array(@openssl_x509_parse($block))) {
                    throw new InvalidArgumentException('The chain contains an invalid certificate.');
                }
            }

            $chain = implode("\n", $blocks[0]);
        }

        $notBefore = (new DateTimeImmutable)->setTimestamp((int) $info['validFrom_time_t']);
        $notAfter = (new DateTimeImmutable)->setTimestamp((int) $info['validTo_time_t']);

        if ($notAfter <= new DateTimeImmutable) {
            throw new InvalidArgumentException('The certificate has expired.');
        }

        $domains = self::domains($info);

        if ($domains === []) {
            throw new InvalidArgumentException('The certificate names no DNS host.');
        }

        $issuer = $info['issuer']['O'] ?? $info['issuer']['CN'] ?? null;

        return new self(
            certPem: $certPem."\n",
            keyPem: trim($keyPem)."\n",
            chainPem: $chain !== null ? $chain."\n" : null,
            domains: $domains,
            issuer: is_array($issuer) ? (string) reset($issuer) : $issuer,
            notBefore: $notBefore,
            notAfter: $notAfter,
            fingerprint: (string) openssl_x509_fingerprint($certPem, 'sha256'),
        );
    }

    /**
     * @param  array<string, mixed>  $info
     * @return list<string>
     */
    private static function domains(array $info): array
    {
        $names = [];

        foreach (explode(',', (string) ($info['extensions']['subjectAltName'] ?? '')) as $entry) {
            $entry = trim($entry);

            if (str_starts_with($entry, 'DNS:')) {
                $names[] = strtolower(substr($entry, 4));
            }
        }

        if ($names === [] && is_string($info['subject']['CN'] ?? null)) {
            $names[] = strtolower($info['subject']['CN']);
        }

        return array_values(array_unique(array_filter($names, fn (string $name) => preg_match('/^(\*\.)?[a-z0-9.-]+$/', $name) === 1)));
    }

    private static function firstBlock(string $pem, string $label): ?string
    {
        return preg_match("/-----BEGIN {$label}-----.+?-----END {$label}-----/s", $pem, $m) === 1 ? $m[0] : null;
    }
}
