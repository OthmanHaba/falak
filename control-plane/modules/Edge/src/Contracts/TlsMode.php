<?php

namespace Kiln\Edge\Contracts;

enum TlsMode: string
{
    /** Caddy obtains and renews a certificate via ACME HTTP/TLS-ALPN challenges. */
    case Auto = 'auto';
    /** Wildcard / private hosts via ACME DNS-01 (Cloudflare API token). */
    case Dns = 'dns';
    /** Uploaded certificate installed with edge.cert.install. */
    case Custom = 'custom';
    /** Caddy's internal CA (self-signed; local / private networks). */
    case Internal = 'internal';
    /** Plain HTTP only. */
    case Off = 'off';

    public function label(): string
    {
        return match ($this) {
            self::Auto => "Automatic (Let's Encrypt)",
            self::Dns => 'DNS-01 (wildcard)',
            self::Custom => 'Custom certificate',
            self::Internal => 'Internal CA',
            self::Off => 'Off (HTTP only)',
        };
    }
}
