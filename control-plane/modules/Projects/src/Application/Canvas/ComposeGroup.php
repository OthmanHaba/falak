<?php

namespace Kiln\Projects\Application\Canvas;

use Kiln\Projects\Domain\Models\Service;
use Kiln\Sites\Contracts\Data\ComposeServiceState;
use Kiln\Sites\Contracts\Data\ComposeSummary;
use Kiln\Sites\Contracts\Data\SiteData;

/**
 * A compose site drawn as a group of its compose services (UI_DESIGN §4.3): one card per compose service with its own
 * status, named volumes as strips, and `depends_on` edges. Positions are relative to the site card's x/y; services
 * without a stored position are laid out on a two-column grid.
 *
 * @phpstan-type ComposeChild array{name: string, icon: string, image: ?string, status: string, status_label: string, url: ?string, volumes: list<string>, position: array{x: int, y: int}}
 */
final class ComposeGroup
{
    /** Default grid inside the group (card 240 wide, ~104 high plus a 28px volume strip). */
    public const COLUMNS = 2;

    public const CELL_WIDTH = 300;

    public const CELL_HEIGHT = 180;

    /** simple-icons keys the canvas knows, matched against the image name (first match wins). */
    private const IMAGE_ICONS = [
        'postgresql' => ['postgres', 'postgis', 'pgvector', 'timescale'],
        'mariadb' => ['mariadb'],
        'mysql' => ['mysql'],
        'redis' => ['redis', 'valkey', 'keydb', 'dragonfly'],
        'mongodb' => ['mongo'],
        'n8n' => ['n8n'],
        'grafana' => ['grafana'],
        'minio' => ['minio'],
        'meilisearch' => ['meilisearch'],
        'clickhouse' => ['clickhouse'],
        'elasticsearch' => ['elasticsearch'],
        'rabbitmq' => ['rabbitmq'],
        'nginx' => ['nginx'],
        'caddy' => ['caddy'],
        'traefik' => ['traefik'],
        'ghost' => ['ghost'],
        'gitea' => ['gitea'],
        'metabase' => ['metabase'],
        'directus' => ['directus'],
        'umami' => ['umami'],
        'plausibleanalytics' => ['plausible'],
        'uptimekuma' => ['uptime-kuma'],
        'vaultwarden' => ['vaultwarden'],
        'listmonk' => ['listmonk'],
        'appsmith' => ['appsmith'],
        'wordpress' => ['wordpress'],
        'node' => ['node'],
        'bun' => ['bun'],
        'php' => ['php'],
    ];

    /**
     * @param  list<ComposeServiceState>  $states
     * @param  array{0: string, 1: string}  $siteStatus  status + label of the site (deployments / targets)
     * @return array{collapsed: bool, services: list<ComposeChild>, edges: list<array{from: string, to: string}>}
     */
    public static function for(Service $service, SiteData $site, ComposeSummary $summary, array $states, array $siteStatus): array
    {
        $layout = is_array($service->layout) ? $service->layout : [];
        $stored = is_array($layout['children'] ?? null) ? $layout['children'] : [];
        $public = [];

        foreach ($site->compose->publicServices ?? [] as $publicService) {
            $public[$publicService->service] = $publicService->url();
        }

        $byService = [];

        foreach ($states as $state) {
            $byService[$state->service][] = $state;
        }

        $children = [];
        $edges = [];
        $cell = 0;

        foreach ($summary->services as $compose) {
            $position = $stored[$compose->name] ?? null;

            if (! is_array($position) || ! isset($position['x'], $position['y'])) {
                $position = ['x' => ($cell % self::COLUMNS) * self::CELL_WIDTH, 'y' => intdiv($cell, self::COLUMNS) * self::CELL_HEIGHT];
            }
            $cell++;

            [$status, $label] = self::status($byService[$compose->name] ?? [], $siteStatus);

            $children[] = [
                'name' => $compose->name,
                'icon' => self::icon($compose->image),
                'image' => $compose->image,
                'status' => $status,
                'status_label' => $label,
                'url' => $public[$compose->name] ?? null,
                'volumes' => $compose->volumes,
                'position' => ['x' => (int) $position['x'], 'y' => (int) $position['y']],
            ];

            foreach ($compose->dependsOn as $dependency) {
                if ($summary->service($dependency) !== null) {
                    $edges[] = ['from' => "{$service->id}:{$compose->name}", 'to' => "{$service->id}:{$dependency}"];
                }
            }
        }

        return ['collapsed' => (bool) ($layout['collapsed'] ?? false), 'services' => $children, 'edges' => $edges];
    }

    /** ServiceIcon key for a compose service's image (`build:` services show the Docker logo). */
    public static function icon(?string $image): string
    {
        if ($image === null || $image === '') {
            return 'docker';
        }

        // registry.example.com/org/name:tag@sha256:… → name
        $name = strtolower((string) preg_replace('/[:@].*$/', '', basename(explode('@', $image)[0])));

        foreach (self::IMAGE_ICONS as $icon => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($name, $needle)) {
                    return $icon;
                }
            }
        }

        return 'docker';
    }

    /**
     * One compose service across the site's servers: any failing container → crashed; all healthy → online; nothing
     * reported yet → the site's own state (not deployed / deploying …).
     *
     * @param  list<ComposeServiceState>  $states
     * @param  array{0: string, 1: string}  $siteStatus
     * @return array{0: string, 1: string}
     */
    private static function status(array $states, array $siteStatus): array
    {
        if (in_array($siteStatus[0], ['deploying', 'building', 'queued', 'provisioning'], true) || $states === []) {
            return $states === [] && $siteStatus[0] === 'active' ? ['inactive', 'Not reported'] : $siteStatus;
        }

        $failing = array_values(array_filter($states, fn (ComposeServiceState $state) => $state->failing()));

        if ($failing !== []) {
            $state = $failing[0];
            $restarts = max(array_map(fn (ComposeServiceState $s) => $s->restarts, $failing));

            return ['crashed', match (true) {
                $state->health === 'unhealthy' => 'Unhealthy',
                $state->state === 'restarting' => 'Restarting'.($restarts > 0 ? " · {$restarts} restarts" : ''),
                default => 'Exited',
            }];
        }

        if (count(array_filter($states, fn (ComposeServiceState $state) => $state->healthy())) === count($states)) {
            return ['active', 'Online'];
        }

        return ['provisioning', 'Starting'];
    }
}
