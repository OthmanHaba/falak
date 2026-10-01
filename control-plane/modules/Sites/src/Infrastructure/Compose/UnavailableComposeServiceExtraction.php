<?php

namespace Kiln\Sites\Infrastructure\Compose;

use Illuminate\Validation\ValidationException;
use Kiln\Databases\Contracts\Data\DatabaseData;
use Kiln\Sites\Contracts\ComposeServiceExtraction;
use Kiln\Sites\Contracts\Data\SiteData;

/**
 * Bound until the extraction implementation is registered: every service stays in the stack.
 */
final class UnavailableComposeServiceExtraction implements ComposeServiceExtraction
{
    public function toDatabase(string $siteId, string $service, ?string $databaseId, string $engine): DatabaseData
    {
        throw ValidationException::withMessages(["compose_services.{$service}.mode" => 'Replacing a compose service with a Kiln database is not available yet.']);
    }

    public function toSite(string $siteId, string $service, array $site): SiteData
    {
        throw ValidationException::withMessages(["compose_services.{$service}.mode" => 'Running a compose service as its own Kiln site is not available yet.']);
    }

    public function rewrites(string $siteId): array
    {
        return [];
    }
}
