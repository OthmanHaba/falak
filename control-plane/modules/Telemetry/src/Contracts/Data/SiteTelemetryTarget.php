<?php

namespace Falak\Telemetry\Contracts\Data;

/**
 * One site's entry in telemetry.configure (`sites[]` plus its log files).
 */
final readonly class SiteTelemetryTarget
{
    /**
     * Log sources are files the agent tails as the site's logs: kind app by default, `multiline: laravel` merges
     * stack traces into their record. The edge access logs are tailed without an entry.
     *
     * @param  list<array{path: string, service?: string, format?: 'plain'|'json', kind?: 'app'|'access', multiline?: 'laravel'}>  $logSources
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
