<?php

namespace Falak\Edge\Application\Jobs;

use Falak\Edge\Application\CloudflareDns;
use Falak\Edge\Domain\Models\Domain;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Brings the Cloudflare DNS records of a domain, a site (all its names) or a removed domain / site in line. Unique per
 * target until it runs, so bursts of changes cost one sync; it reads the state at run time.
 */
final class SyncCloudflareDns implements ShouldBeUnique, ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public int $uniqueFor = 300;

    /** @param  'domain'|'site'|'forget'|'forget-site'  $scope */
    public function __construct(public readonly string $scope, public readonly string $id) {}

    public static function domain(string $domainId): void
    {
        self::dispatch('domain', $domainId);
    }

    public static function site(string $siteId): void
    {
        self::dispatch('site', $siteId);
    }

    public static function forget(string $domainId): void
    {
        self::dispatch('forget', $domainId);
    }

    public static function forgetSite(string $siteId): void
    {
        self::dispatch('forget-site', $siteId);
    }

    public function uniqueId(): string
    {
        return "{$this->scope}:{$this->id}";
    }

    public function handle(CloudflareDns $dns): void
    {
        match ($this->scope) {
            'site' => $dns->syncSite($this->id),
            'forget' => $dns->forget($this->id),
            'forget-site' => $dns->forgetSite($this->id),
            default => ($domain = Domain::query()->find($this->id)) !== null ? $dns->sync($domain) : $dns->forget($this->id),
        };
    }
}
