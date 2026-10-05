<?php

namespace Falak\Fleet\Infrastructure\Pki;

use DateTimeImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Falak\Fleet\Domain\Models\CertificateAuthority;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\EC\PrivateKey as EcPrivateKey;
use phpseclib3\Crypt\EC\PublicKey as EcPublicKey;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\File\X509;
use RuntimeException;
use Throwable;

/**
 * Falak's internal CA (ECDSA P-256). The key lives only in the database, encrypted with APP_KEY;
 * the CA certificate is also written to <fleet.ca_path>/ca.pem for the edge's client-cert verification.
 */
final class CertificateAuthorityService
{
    private const CURVE = 'secp256r1';

    private ?CertificateAuthority $current = null;

    public function __construct(
        private readonly Cache $cache,
        private readonly string $caPath,
        private readonly int $caValidityYears = 10,
        private readonly int $certValidityDays = 90,
    ) {}

    /**
     * The active CA, created on first use.
     */
    public function current(): CertificateAuthority
    {
        if ($this->current !== null) {
            return $this->current;
        }

        $ca = CertificateAuthority::query()->where('active', true)->latest()->first();

        if ($ca === null) {
            $ca = $this->cache->lock('fleet:ca:create', 30)->block(15, function () {
                return CertificateAuthority::query()->where('active', true)->latest()->first() ?? $this->create();
            });
        }

        $this->writeCaFile($ca);

        return $this->current = $ca;
    }

    public function caPem(): string
    {
        return self::normalizePem($this->current()->certificate_pem);
    }

    /**
     * PEM blocks always end with exactly one newline, so concatenated bundles stay parseable
     * (phpseclib emits no trailing newline).
     */
    public static function normalizePem(string $pem): string
    {
        return rtrim(str_replace("\r\n", "\n", $pem))."\n";
    }

    /**
     * Concatenate PEM blocks into a bundle (leaf first).
     */
    public static function bundle(string ...$pems): string
    {
        return implode('', array_map(self::normalizePem(...), $pems));
    }

    public function caFilePath(): string
    {
        return rtrim($this->caPath, '/').'/ca.pem';
    }

    /**
     * Sign an agent CSR. The subject is replaced (CN = agent id); only the CSR's public key is used.
     *
     * @throws InvalidCsr
     */
    public function signAgentCsr(string $csrPem, string $agentId, string $organizationId): IssuedCertificate
    {
        $publicKey = $this->publicKeyFromCsr($csrPem);

        $subject = new X509;
        $subject->setPublicKey($publicKey);
        $subject->setDNProp('id-at-commonName', $agentId);
        $subject->setDNProp('id-at-organizationName', 'Falak Agent');
        $subject->setDNProp('id-at-organizationalUnitName', $organizationId);

        return $this->issue($subject, fn (X509 $cert) => $this->clientExtensions($cert), $this->certValidityDays);
    }

    /**
     * Issue a TLS server certificate for the agent-facing edge (agents pin the Falak CA).
     *
     * @param  list<string>  $hostnames
     * @return array{certificate: IssuedCertificate, private_key_pem: string}
     */
    public function issueServerCertificate(array $hostnames, int $validityDays = 397): array
    {
        if ($hostnames === []) {
            throw new RuntimeException('At least one hostname is required.');
        }

        $key = EC::createKey(self::CURVE);

        $subject = new X509;
        $subject->setPublicKey($key->getPublicKey());
        $subject->setDNProp('id-at-commonName', $hostnames[0]);
        $subject->setDNProp('id-at-organizationName', 'Falak');

        $certificate = $this->issue($subject, function (X509 $cert) use ($hostnames) {
            $cert->setExtensionValue('id-ce-keyUsage', ['digitalSignature'], true);
            $cert->setExtensionValue('id-ce-extKeyUsage', ['id-kp-serverAuth']);
            $cert->setExtensionValue('id-ce-basicConstraints', ['cA' => false], true);
            $cert->setExtensionValue('id-ce-subjectAltName', array_map(
                fn (string $host) => filter_var($host, FILTER_VALIDATE_IP) ? ['iPAddress' => $host] : ['dNSName' => $host],
                $hostnames,
            ));
        }, $validityDays);

        return ['certificate' => $certificate, 'private_key_pem' => self::normalizePem($key->toString('PKCS8'))];
    }

    public static function fingerprint(string $certificatePem): string
    {
        return hash('sha256', self::der($certificatePem));
    }

    public static function der(string $pem): string
    {
        if (! preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $pem, $m)) {
            throw new RuntimeException('Not a PEM certificate.');
        }

        return (string) base64_decode((string) preg_replace('/\s+/', '', $m[1]), true);
    }

    /**
     * @throws InvalidCsr
     */
    private function publicKeyFromCsr(string $csrPem): EcPublicKey
    {
        $x509 = new X509;

        try {
            $loaded = $x509->loadCSR($csrPem);
        } catch (Throwable) {
            $loaded = false;
        }

        if ($loaded === false) {
            throw new InvalidCsr('The CSR could not be parsed.');
        }

        if ($x509->validateSignature() !== true) {
            throw new InvalidCsr('The CSR signature is invalid.');
        }

        $key = $x509->getPublicKey();

        if (! $key instanceof EcPublicKey || $key->getCurve() !== self::CURVE) {
            throw new InvalidCsr('The CSR must contain an ECDSA P-256 public key.');
        }

        return $key;
    }

    private function clientExtensions(X509 $cert): void
    {
        $cert->setExtensionValue('id-ce-keyUsage', ['digitalSignature'], true);
        $cert->setExtensionValue('id-ce-extKeyUsage', ['id-kp-clientAuth']);
        $cert->setExtensionValue('id-ce-basicConstraints', ['cA' => false], true);
    }

    /**
     * @param  callable(X509): void  $extensions
     */
    private function issue(X509 $subject, callable $extensions, int $validityDays): IssuedCertificate
    {
        $ca = $this->current();

        $issuer = new X509;
        $issuer->loadX509($ca->certificate_pem);
        $issuer->setPrivateKey($this->caKey($ca));

        $notBefore = new DateTimeImmutable('-5 minutes');
        $notAfter = new DateTimeImmutable("+{$validityDays} days");
        $serial = $this->serial();

        $cert = new X509;
        $cert->setStartDate($notBefore);
        $cert->setEndDate($notAfter);
        $cert->setSerialNumber($serial, 16);
        $extensions($cert);

        $signed = $cert->sign($issuer, $subject);

        if ($signed === false) {
            throw new RuntimeException('Certificate signing failed.');
        }

        $pem = self::normalizePem($cert->saveX509($signed));

        return new IssuedCertificate($pem, $serial, self::fingerprint($pem), $notBefore, $notAfter);
    }

    private function create(): CertificateAuthority
    {
        $key = EC::createKey(self::CURVE);

        $subject = new X509;
        $subject->setPublicKey($key->getPublicKey());
        $subject->setDNProp('id-at-commonName', 'Falak Agent CA');
        $subject->setDNProp('id-at-organizationName', 'Falak');

        $issuer = new X509;
        $issuer->setPrivateKey($key);
        $issuer->setDN($subject->getDN());

        $notBefore = new DateTimeImmutable('-5 minutes');
        $notAfter = new DateTimeImmutable("+{$this->caValidityYears} years");

        $cert = new X509;
        $cert->makeCA();
        $cert->setStartDate($notBefore);
        $cert->setEndDate($notAfter);
        $cert->setSerialNumber($this->serial(), 16);

        $signed = $cert->sign($issuer, $subject);

        if ($signed === false) {
            throw new RuntimeException('CA self-signing failed.');
        }

        $pem = self::normalizePem($cert->saveX509($signed));

        return DB::transaction(fn () => CertificateAuthority::query()->create([
            'name' => 'Falak Agent CA',
            'certificate_pem' => $pem,
            'private_key' => self::normalizePem($key->toString('PKCS8')),
            'fingerprint' => self::fingerprint($pem),
            'not_before' => $notBefore,
            'not_after' => $notAfter,
            'active' => true,
        ]));
    }

    private function caKey(CertificateAuthority $ca): PrivateKey
    {
        $key = PublicKeyLoader::loadPrivateKey($ca->private_key);

        if (! $key instanceof EcPrivateKey) {
            throw new RuntimeException('The CA key is not an EC key.');
        }

        return $key;
    }

    /** 128-bit positive serial (hex). */
    private function serial(): string
    {
        $bytes = random_bytes(16);
        $bytes[0] = chr(ord($bytes[0]) & 0x7F);

        return bin2hex($bytes);
    }

    private function writeCaFile(CertificateAuthority $ca): void
    {
        $file = $this->caFilePath();

        $pem = self::normalizePem($ca->certificate_pem);

        if (is_file($file) && file_get_contents($file) === $pem) {
            return;
        }

        if (! is_dir(dirname($file)) && ! @mkdir(dirname($file), 0755, true) && ! is_dir(dirname($file))) {
            throw new RuntimeException('Cannot create CA directory ['.dirname($file).'].');
        }

        $tmp = $file.'.'.bin2hex(random_bytes(4)).'.tmp';
        file_put_contents($tmp, $pem);
        chmod($tmp, 0644);
        rename($tmp, $file);
    }
}
