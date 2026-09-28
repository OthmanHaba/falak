<?php

namespace Kiln\Sites\Infrastructure;

use Illuminate\Validation\ValidationException;
use Kiln\Sites\Application\ComposeSettings;
use Kiln\Sites\Contracts\Data\DomainChoice;
use Kiln\Sites\Contracts\DomainType;
use Kiln\Sites\Contracts\SiteDomains;

/**
 * Used until a domains owner (Edge) rebinds SiteDomains: only the test domain and custom names, nothing routed.
 */
final class NullSiteDomains implements SiteDomains
{
    public function primaryDomains(array $siteIds): array
    {
        return [];
    }

    public function resolveChoice(string $organizationId, ?DomainChoice $choice, string $label, array $serverIds, string $field, ?string $siteId = null): ?string
    {
        $testDomain = is_string(config('sites.test_domain')) && config('sites.test_domain') !== '';

        return match ($choice?->type) {
            null, DomainType::Test => $testDomain ? null : throw ValidationException::withMessages([$field => 'No test domain is configured; enter a domain.']),
            DomainType::Generated => throw ValidationException::withMessages([$field => 'Generated domains are not available.']),
            DomainType::Custom => preg_match(ComposeSettings::DOMAIN_PATTERN, (string) $choice->name) === 1
                ? (string) $choice->name
                : throw ValidationException::withMessages([$field => 'Enter a domain name like app.example.com.']),
        };
    }

    public function attach(string $siteId, string $name): void {}
}
