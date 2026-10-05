<?php

namespace Falak\Edge\Http\Controllers;

use Falak\Edge\Application\CloudflareConnections;
use Falak\Edge\Application\CloudflareEdgeControls;
use Falak\Edge\Application\CloudflareTunnels;
use Falak\Edge\Application\GeneratedDomains;
use Falak\Edge\Application\Jobs\SyncCloudflareDns;
use Falak\Edge\Domain\Models\CloudflareTunnel;
use Falak\Edge\Domain\Models\CloudflareZone;
use Falak\Edge\Domain\Models\DnsCredential;
use Falak\Edge\Domain\Models\DnsRecord;
use Falak\Edge\Domain\Models\Domain;
use Falak\Edge\Domain\Models\OrganizationSetting;
use Falak\Edge\Domain\Models\OriginLock;
use Falak\Edge\Infrastructure\Cloudflare\CloudflareError;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Servers\Contracts\Data\ServerData;
use Falak\Servers\Contracts\ServerDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Integrations → Cloudflare.
 */
final class CloudflareController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly CloudflareConnections $connections,
        private readonly CloudflareTunnels $tunnels,
        private readonly ServerDirectory $servers,
    ) {}

    public function show(Request $request): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'edge.view');
        $generated = (string) OrganizationSetting::for($organizationId)->generated_domain_provider;

        $connections = DnsCredential::query()->where('organization_id', $organizationId)->where('provider', 'cloudflare')->orderBy('name')->get()
            ->map(function (DnsCredential $credential) {
                $error = null;
                try {
                    $zones = $this->connections->zones($credential);
                } catch (CloudflareError $e) {
                    [$zones, $error] = [[], $e->getMessage()];
                }

                return [
                    'id' => $credential->id,
                    'name' => $credential->name,
                    'verified_at' => $credential->verified_at?->toIso8601String(),
                    'error' => $error,
                    'zones' => $zones,
                ];
            });

        $zones = CloudflareZone::query()->with('credential')->where('organization_id', $organizationId)->orderBy('name')->get()
            ->map(fn (CloudflareZone $zone) => [
                'id' => $zone->id,
                'name' => $zone->name,
                'connection' => $zone->credential->name,
                'proxied' => $zone->proxied,
                'generates' => $generated === GeneratedDomains::CLOUDFLARE.$zone->name,
                'under_attack' => $zone->security_level_before !== null,
                'health' => $this->connections->health($zone),
                'records' => DnsRecord::query()->where('zone_id', $zone->id)->orderBy('name')->get()
                    ->map(fn (DnsRecord $r) => ['name' => $r->name, 'type' => $r->type, 'content' => $r->content, 'proxied' => $r->proxied, 'status' => $r->status, 'error' => $r->error])->values(),
            ]);

        $tunnels = CloudflareTunnel::query()->with('credential')->where('organization_id', $organizationId)->get()->keyBy('server_id');
        $locks = OriginLock::query()->where('organization_id', $organizationId)->get()->keyBy('server_id');
        $servers = collect($this->servers->forOrganization($organizationId))->map(fn (ServerData $server) => [
            'id' => $server->id,
            'name' => $server->name,
            'ipv4' => $server->ipv4,
            'lock' => $locks->get($server->id)?->mode,
            'tunnel' => ($t = $tunnels->get($server->id)) ? [
                'id' => $t->id,
                'name' => $t->name,
                'connection' => $t->credential->name,
                'status' => $t->status,
                'error' => $t->error,
                'cname' => $t->hostname(),
                'health' => $this->tunnels->health($t),
            ] : null,
        ])->sortBy('name')->values();

        return Inertia::render('Edge/Cloudflare', [
            'connections' => $connections->values(),
            'zones' => $zones->values(),
            'servers' => $servers,
            'can' => ['manage' => $this->access->can($request->user(), $organizationId, 'edge.dns.manage')],
        ]);
    }

    public function connect(Request $request): RedirectResponse
    {
        $organizationId = $this->manage($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'api_token' => ['required', 'string', 'min:20', 'max:500'],
        ]);

        $this->connections->connect($organizationId, $data['name'], $data['api_token'], $request->user()?->getAuthIdentifier());

        return back()->with('success', 'Cloudflare connected. Pick the zones Falak should manage.');
    }

    public function disconnect(Request $request, string $credential): RedirectResponse
    {
        $organizationId = $this->manage($request);
        $this->connections->disconnect(DnsCredential::query()->where('organization_id', $organizationId)->where('provider', 'cloudflare')->findOrFail($credential));

        return back()->with('success', 'Cloudflare disconnected. DNS records stay in Cloudflare; Falak no longer changes them.');
    }

    public function enable(Request $request, string $credential): RedirectResponse
    {
        $organizationId = $this->manage($request);
        $data = $request->validate(['zone_id' => ['required', 'string', 'max:64'], 'proxied' => ['sometimes', 'boolean'], 'generate' => ['sometimes', 'boolean']]);
        $model = DnsCredential::query()->where('organization_id', $organizationId)->where('provider', 'cloudflare')->findOrFail($credential);

        try {
            $zone = $this->connections->enable($model, $data['zone_id'], (bool) ($data['proxied'] ?? true));
        } catch (CloudflareError $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($data['generate'] ?? false) {
            OrganizationSetting::for($organizationId)->forceFill(['generated_domain_provider' => GeneratedDomains::CLOUDFLARE.$zone->name])->save();
        }

        return back()->with('success', "Falak now manages DNS for {$zone->name}.");
    }

    public function update(Request $request, string $zone): RedirectResponse
    {
        $organizationId = $this->manage($request);
        $model = $this->zone($organizationId, $zone);
        $data = $request->validate(['proxied' => ['sometimes', 'boolean'], 'generate' => ['sometimes', 'boolean']]);

        if (array_key_exists('proxied', $data)) {
            $this->connections->updateZone($model, (bool) $data['proxied']);
        }

        if (array_key_exists('generate', $data)) {
            $setting = OrganizationSetting::for($organizationId);
            $mine = $setting->generated_domain_provider === GeneratedDomains::CLOUDFLARE.$model->name;
            if ($data['generate'] || $mine) {
                $setting->forceFill(['generated_domain_provider' => $data['generate'] ? GeneratedDomains::CLOUDFLARE.$model->name : null])->save();
            }
        }

        return back()->with('success', 'Zone updated.');
    }

    public function disable(Request $request, string $zone): RedirectResponse
    {
        $organizationId = $this->manage($request);
        $data = $request->validate(['delete_records' => ['sometimes', 'boolean']]);
        $this->connections->disable($this->zone($organizationId, $zone), (bool) ($data['delete_records'] ?? false));

        return back()->with('success', ($data['delete_records'] ?? false) ? 'Zone released and Falak’s records deleted.' : 'Zone released. Its DNS records stay in Cloudflare.');
    }

    public function setting(Request $request, string $zone): RedirectResponse
    {
        $organizationId = $this->manage($request);
        $data = $request->validate(['setting' => ['required', Rule::in(array_keys(CloudflareConnections::RECOMMENDED))]]);
        $model = $this->zone($organizationId, $zone);
        $this->connections->applyRecommended($model, $data['setting']);

        return back()->with('success', "{$model->name}: {$data['setting']} set to ".CloudflareConnections::RECOMMENDED[$data['setting']].'.');
    }

    /** Re-sync every managed domain of the zone (after fixing a conflict in Cloudflare). */
    public function sync(Request $request, string $zone): RedirectResponse
    {
        $organizationId = $this->manage($request);
        $model = $this->zone($organizationId, $zone);
        Domain::query()->where('organization_id', $organizationId)->get()->filter(fn (Domain $d) => $model->covers($d->name))
            ->each(fn (Domain $d) => SyncCloudflareDns::domain($d->id));

        return back()->with('success', "Syncing DNS records for {$model->name}.");
    }

    /** Server ingress → Cloudflare Tunnel. */
    public function enableTunnel(Request $request): RedirectResponse
    {
        $organizationId = $this->manage($request);
        $data = $request->validate(['server_id' => ['required', 'string', 'size:26'], 'connection_id' => ['required', 'string', 'size:26']]);
        $credential = DnsCredential::query()->where('organization_id', $organizationId)->where('provider', 'cloudflare')->findOrFail($data['connection_id']);

        $tunnel = $this->tunnels->enable($data['server_id'], $credential, $request->user()?->getAuthIdentifier());

        return back()->with('success', "Installing cloudflared on the server; its names move to {$tunnel->hostname()}.");
    }

    public function disableTunnel(Request $request, string $tunnel): RedirectResponse
    {
        $organizationId = $this->manage($request);
        $this->tunnels->disable(CloudflareTunnel::query()->with('credential')->where('organization_id', $organizationId)->findOrFail($tunnel));

        return back()->with('success', 'Tunnel removed: the server is reached on its public IP again.');
    }

    public function reinstallTunnel(Request $request, string $tunnel): RedirectResponse
    {
        $organizationId = $this->manage($request);
        $this->tunnels->reinstall(CloudflareTunnel::query()->with('credential')->where('organization_id', $organizationId)->findOrFail($tunnel));

        return back()->with('success', 'Reinstalling cloudflared.');
    }

    /** Under Attack mode for a zone. */
    public function underAttack(Request $request, string $zone, CloudflareEdgeControls $controls): RedirectResponse
    {
        $organizationId = $this->manage($request);
        $data = $request->validate(['on' => ['required', 'boolean']]);
        $model = $this->zone($organizationId, $zone);
        $controls->underAttack($model, (bool) $data['on']);

        return back()->with('success', $data['on'] ? "{$model->name} is in Under Attack mode: visitors get a browser check first." : "Under Attack mode is off for {$model->name}.");
    }

    /** Origin lock-down of a server's web ports: open, cloudflare (Cloudflare's ranges only) or closed (tunnel). */
    public function lock(Request $request, string $server, CloudflareEdgeControls $controls): RedirectResponse
    {
        $organizationId = $this->manage($request);
        $data = $request->validate(['mode' => ['present', 'nullable', Rule::in([OriginLock::CLOSED, OriginLock::CLOUDFLARE])]]);
        $model = collect($this->servers->forOrganization($organizationId))->firstWhere('id', strtolower($server)) ?? abort(404);
        $controls->lock($organizationId, $model->id, $data['mode']);

        return back()->with('success', match ($data['mode']) {
            OriginLock::CLOSED => "{$model->name}: ports 80 and 443 are closed; it is reached through its tunnel only.",
            OriginLock::CLOUDFLARE => "{$model->name}: ports 80 and 443 accept Cloudflare only.",
            default => "{$model->name}: ports 80 and 443 follow its firewall rules again.",
        });
    }

    private function manage(Request $request): string
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'edge.dns.manage');

        return $organizationId;
    }

    private function zone(string $organizationId, string $zone): CloudflareZone
    {
        return CloudflareZone::query()->with('credential')->where('organization_id', $organizationId)->findOrFail($zone);
    }
}
