<?php

namespace Falak\Sites\Http\Controllers;

use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Kernel\Http\Controller;
use Falak\Limits\Contracts\LimitDefaults;
use Falak\Limits\Contracts\ResourceLimits;
use Falak\Sites\Application\Actions\UpdateSiteLimits;
use Falak\Sites\Contracts\ComposeInspector;
use Falak\Sites\Contracts\ComposeSites;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Domain\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A site's resource limits (Settings → Resources), and those of its compose services. Web and API (JSON both).
 */
final class SiteLimitsController extends Controller
{
    public function __construct(private readonly LimitDefaults $defaults) {}

    /**
     * GET /sites/{site}/limits
     */
    public function show(Request $request, Site $site, AgentDirectory $agents, ComposeSites $compose, ComposeInspector $inspector): JsonResponse
    {
        $this->authorize('view', $site);
        $site->load('targets');
        $own = ResourceLimits::fromArray($site->limits);
        $defaults = $this->defaults->forSite($site->id);
        $services = [];

        if ($site->runtime === SiteRuntime::Compose) {
            foreach ($inspector->parse($compose->project($site->id) ?? '')->serviceNames() as $name) {
                $limits = ResourceLimits::fromArray($site->compose_limits[$name] ?? null);
                $services[] = ['name' => $name, 'limits' => (object) $limits->toArray(), 'effective' => (object) $limits->withDefaults($defaults)->toArray()];
            }
        }

        // The smallest server bounds what can be set.
        $memory = $cpus = null;

        foreach ($agents->forServers($site->serverIds()) as $agent) {
            if (is_numeric($agent->facts['memory_bytes'] ?? null)) {
                $memory = min($memory ?? PHP_INT_MAX, intdiv((int) $agent->facts['memory_bytes'], 1024 ** 2));
            }

            if (is_numeric($agent->facts['cpus'] ?? null)) {
                $cpus = min($cpus ?? PHP_INT_MAX, (int) $agent->facts['cpus']);
            }
        }

        return response()->json(['data' => [
            'runtime' => $site->runtime->value,
            // Compose projects are limited per service; FrankenPHP sites only get restart / log / OOM settings.
            'scope' => match ($site->runtime) {
                SiteRuntime::Compose => 'services',
                SiteRuntime::Static, SiteRuntime::Function => 'none',
                SiteRuntime::FrankenPhp => 'policy',
                default => 'site',
            },
            'limits' => (object) $own->toArray(),
            'effective' => (object) $own->withDefaults($defaults)->toArray(),
            'defaults' => (object) $defaults->toArray(),
            'services' => $services,
            'bounds' => ['memory_mb' => $memory, 'cpus' => $cpus, 'min_memory_mb' => ResourceLimits::MIN_MEMORY_MB],
            'options' => ['restart_policies' => ResourceLimits::RESTART_POLICIES, 'oom' => ResourceLimits::OOM_PREFERENCES],
            'can' => ['update' => $request->user()?->can('update', $site) ?? false],
        ]]);
    }

    /**
     * PUT /sites/{site}/limits {limits} — PUT /sites/{site}/compose/services/{service}/limits {limits}
     */
    public function update(Request $request, Site $site, UpdateSiteLimits $update, ?string $service = null): JsonResponse
    {
        $this->authorize('update', $site);
        $request->validate(['limits' => ['present', 'nullable', 'array']]);
        $input = $request->input('limits');

        $applied = $update($site, is_array($input) ? $input : null, $service, $request->user()?->getAuthIdentifier());
        $site->refresh();
        $own = $service !== null ? ResourceLimits::fromArray($site->compose_limits[$service] ?? null) : ResourceLimits::fromArray($site->limits);

        return response()->json(['data' => [
            'limits' => (object) $own->toArray(),
            'effective' => (object) $this->defaults->effective($own, $site->id)->toArray(),
            // live: running containers / slices changed now; redeploy: on the next deploy; none: nothing to apply yet.
            'applied' => $applied,
        ]]);
    }
}
