<?php

namespace Kiln\Edge\Application;

use Kiln\Edge\Domain\Models\CloudflareZone;
use Kiln\Edge\Domain\Models\OrganizationSetting;

/**
 * Generated names: `<label>.<ip-with-dashes>.<suffix>` (e.g. `minio-files.63-182-218-247.sslip.io`), or
 * `<label>.<zone>` under a Cloudflare zone Kiln manages (the record is created for it). Wildcard DNS
 * services such as sslip.io and nip.io answer with the IP embedded in the name, so the name works without any DNS
 * setup and Let's Encrypt can issue a certificate over HTTP-01.
 */
final class GeneratedDomains
{
    /** Providers offered in Settings → Domains (the server default may be another, self-hosted one). */
    public const PROVIDERS = ['sslip.io', 'nip.io'];

    public const OFF = 'off';

    /** Setting value prefix for names under a Cloudflare zone Kiln manages (`cloudflare:example.com`). */
    public const CLOUDFLARE = 'cloudflare:';

    private const SUFFIX = '/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/';

    /** The server default (KILN_GENERATED_DOMAIN_SUFFIX); null when off or invalid. */
    public function defaultSuffix(): ?string
    {
        return self::normalizeSuffix(config('edge.generated_domain_suffix'));
    }

    /** The suffix an organization's generated names use; null when generated names are off. */
    public function suffix(string $organizationId): ?string
    {
        if (($zone = $this->zone($organizationId)) !== null) {
            return $zone->name;
        }

        $provider = OrganizationSetting::for($organizationId)->generated_domain_provider;

        return $provider === null ? $this->defaultSuffix() : self::normalizeSuffix($provider);
    }

    /**
     * The Cloudflare zone generated names live under (`<label>.<zone>`: the site slug, which already carries the
     * environment for copies, e.g. shop-staging.example.com — one level, so Cloudflare's free certificate covers it).
     */
    public function zone(string $organizationId): ?CloudflareZone
    {
        $provider = (string) OrganizationSetting::for($organizationId)->generated_domain_provider;

        return str_starts_with($provider, self::CLOUDFLARE)
            ? CloudflareZone::query()->with('credential')->where('organization_id', $organizationId)->where('name', substr($provider, strlen(self::CLOUDFLARE)))->first()
            : null;
    }

    public static function normalizeSuffix(mixed $value): ?string
    {
        $suffix = is_string($value) ? strtolower(trim($value, ". \t")) : '';

        return $suffix === '' || $suffix === self::OFF || preg_match(self::SUFFIX, $suffix) !== 1 ? null : $suffix;
    }

    /** A DNS label from a service / site name: lowercase letters, digits and dashes, at most 63 characters. */
    public static function label(string $label): string
    {
        $label = trim((string) preg_replace('/-+/', '-', (string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($label))), '-');

        return rtrim(substr($label, 0, 63), '-') ?: 'app';
    }

    public static function name(string $label, string $ipv4, string $suffix): string
    {
        return self::label($label).'.'.str_replace('.', '-', $ipv4).'.'.$suffix;
    }
}
