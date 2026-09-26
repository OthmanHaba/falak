<?php

namespace Kiln\Telemetry\Contracts\Data;

/**
 * One site's entry in telemetry.configure (`sites[]` plus its log files).
 */
final readonly class SiteTelemetryTarget
{
    /**
     * @param  list<array{path: string, service?: string, format?: 'plain'|'json'}>  $logSources
     */
    public function __construct(
        public string $siteId,
        public string $slug,
        public ?string $environment = null,
        public ?string $deploymentId = null,
        public ?string $releaseId = null,
        public array $logSources = [],
    ) {}
}
