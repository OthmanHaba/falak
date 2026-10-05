<?php

namespace Falak\Edge\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Falak\Edge\Application\CloudflareRateLimits;
use Falak\Edge\Domain\Models\CloudflareZone;
use Falak\Edge\Domain\Models\Domain;
use Falak\Edge\Infrastructure\Cloudflare\CloudflareError;
use Falak\Kernel\Http\Controller;

/**
 * A domain's Cloudflare rate limit (panel Settings → Networking and API v1). {domain} is the domain's id or name.
 */
final class RateLimitController extends Controller
{
    use ResolvesSite;

    /** GET — the rule and what the zone's plan allows. */
    public function show(Request $request, string $site, string $domain, CloudflareRateLimits $limits): JsonResponse
    {
        $siteData = $this->site($request, $site);
        $model = $this->domain($siteData->id, $domain);

        return response()->json(['data' => $this->present($model, $limits)]);
    }

    /** PUT {path?, requests, period, action, timeout} — timeout is ignored (0) for a managed challenge below Enterprise. */
    public function update(Request $request, string $site, string $domain, CloudflareRateLimits $limits): JsonResponse
    {
        $siteData = $this->site($request, $site, 'edge.manage');
        $model = $this->domain($siteData->id, $domain);
        $data = $request->validate([
            'path' => ['nullable', 'string', 'max:200'],
            'requests' => ['required', 'integer'],
            'period' => ['required', 'integer'],
            'action' => ['required', Rule::in(CloudflareRateLimits::ACTIONS)],
            'timeout' => ['nullable', 'integer'],
        ]);

        $this->apply(fn () => $limits->set($model, $data, $request->user()?->getAuthIdentifier()));

        return response()->json(['data' => $this->present($model->refresh(), $limits)]);
    }

    /** DELETE — removes the domain's rule. */
    public function destroy(Request $request, string $site, string $domain, CloudflareRateLimits $limits): JsonResponse
    {
        $siteData = $this->site($request, $site, 'edge.manage');
        $model = $this->domain($siteData->id, $domain);

        if ($model->cloudflare_rate_limit !== null) {
            $this->apply(fn () => $limits->set($model, null, $request->user()?->getAuthIdentifier()));
        }

        return response()->json(['data' => $this->present($model->refresh(), $limits)]);
    }

    private function apply(callable $change): void
    {
        try {
            $change();
        } catch (CloudflareError $e) {
            throw ValidationException::withMessages(['rate_limit' => $e->getMessage().' (the token needs Zone → Zone WAF → Edit)']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Domain $domain, CloudflareRateLimits $limits): array
    {
        $zone = CloudflareZone::forHost($domain->organization_id, $domain->name);

        return [
            'domain' => $domain->name,
            'rule' => $domain->cloudflare_rate_limit,
            'zone' => $zone?->name,
            'proxied' => $zone !== null && ($domain->cloudflare_proxied ?? $zone->proxied),
            'limits' => $zone !== null ? CloudflareRateLimits::limits($limits->plan($zone)) : null,
            // Another domain's Free-plan rule that applies zone-wide, so to this domain too.
            'zone_rule' => $zone !== null ? $limits->zoneWideRule($domain, $zone) : null,
        ];
    }

    private function domain(string $siteId, string $domain): Domain
    {
        return Domain::query()->where('site_id', $siteId)
            ->where(fn ($q) => $q->where('id', strtolower($domain))->orWhere('name', strtolower($domain)))
            ->firstOrFail();
    }
}
