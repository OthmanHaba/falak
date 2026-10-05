<?php

namespace Falak\Deployments\Application\Orchestration;

use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\Data\SiteTargetData;
use Falak\Sites\Contracts\TargetStatus;

/**
 * Where a site's servers are in their preparation (unix user, PHP-FPM pool, JS runtime), as seen by a deployment
 * about to start:
 *
 * - any server still pending/provisioning → the deployment waits for all of them (it deploys to every server
 *   once, rather than leaving late servers without a release);
 * - servers whose preparation failed are skipped with a warning, as long as another server is ready and the
 *   leader (which runs migrations) is not among them;
 * - a failed leader, or no ready server at all, fails the deployment.
 */
final readonly class TargetReadiness
{
    /**
     * @param  list<SiteTargetData>  $ready
     * @param  list<SiteTargetData>  $preparing
     * @param  list<SiteTargetData>  $failed
     * @param  array<string, string>  $names  server id => display name
     */
    private function __construct(
        public array $ready,
        public array $preparing,
        public array $failed,
        private array $names,
    ) {}

    public static function of(SiteData $site, ServerDirectory $servers): self
    {
        $ready = $preparing = $failed = [];
        $names = [];

        foreach ($site->targets as $target) {
            $names[$target->serverId] = $servers->find($target->serverId)?->name ?? $target->serverId;

            match ($target->status) {
                TargetStatus::Ready => $ready[] = $target,
                TargetStatus::Pending, TargetStatus::Provisioning => $preparing[] = $target,
                TargetStatus::Failed => $failed[] = $target,
                TargetStatus::Removing => null,
            };
        }

        return new self($ready, $preparing, $failed, $names);
    }

    public function mustWait(): bool
    {
        return $this->preparing !== [] && $this->blocker() === null;
    }

    /**
     * Why the deployment cannot run at all (null when it can start or wait).
     */
    public function blocker(): ?string
    {
        foreach ($this->failed as $target) {
            if ($target->isLeader()) {
                return sprintf('Preparing the site failed on its leader server %s%s. Retry the server (site Settings → Servers) or pick another leader, then deploy again.',
                    $this->name($target), self::because($target));
            }
        }

        if ($this->preparing === [] && $this->ready === []) {
            if ($this->failed === []) {
                return 'The site has no servers to deploy to.';
            }

            return 'Preparing the site failed on every server: '.implode('; ', array_map(fn (SiteTargetData $t) => $this->name($t).self::because($t), $this->failed)).'.';
        }

        return null;
    }

    /**
     * "Waiting for 2 servers to finish preparing: web-1, web-2".
     */
    public function waitingReason(): string
    {
        $count = count($this->preparing);

        return sprintf('Waiting for %d %s to finish preparing: %s', $count, $count === 1 ? 'server' : 'servers',
            implode(', ', array_map(fn (SiteTargetData $t) => $this->name($t), $this->preparing)));
    }

    /**
     * Warnings for servers that are skipped because their preparation failed.
     *
     * @return list<string>
     */
    public function skipped(): array
    {
        return array_map(fn (SiteTargetData $t) => sprintf('%s is skipped: preparing the site failed%s. Retry it from the site\'s servers, then redeploy.',
            $this->name($t), self::because($t)), $this->failed);
    }

    public function name(SiteTargetData $target): string
    {
        return $this->names[$target->serverId] ?? $target->serverId;
    }

    private static function because(SiteTargetData $target): string
    {
        return $target->statusMessage !== null && $target->statusMessage !== '' ? ' ('.rtrim($target->statusMessage, '.').')' : '';
    }
}
