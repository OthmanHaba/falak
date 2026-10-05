<?php

namespace Falak\Edge\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Falak\Edge\Application\GeneratedDomains;
use Falak\Edge\Domain\Models\CloudflareZone;
use Falak\Edge\Domain\Models\OrganizationSetting;
use Falak\Edge\Infrastructure\EloquentSiteDomains;
use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;

/**
 * Organization settings → Domains: which service generated domains use (or off), and the test domain in effect.
 */
final class DomainSettingsController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly GeneratedDomains $generated,
    ) {}

    public function show(Request $request): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'edge.view');

        return Inertia::render('Edge/DomainSettings', [
            'settings' => [
                'provider' => OrganizationSetting::for($organizationId)->generated_domain_provider ?? 'default',
                'effective_suffix' => $this->generated->suffix($organizationId),
                'default_suffix' => $this->generated->defaultSuffix(),
                'providers' => GeneratedDomains::PROVIDERS,
                // Cloudflare zones Falak manages (Settings → Cloudflare): names like shop.example.com.
                'zones' => CloudflareZone::query()->where('organization_id', $organizationId)->orderBy('name')->pluck('name')->all(),
                'test_domain' => EloquentSiteDomains::testDomainBase(),
            ],
            'can' => ['manage' => $this->access->can($request->user(), $organizationId, 'edge.dns.manage')],
        ]);
    }

    public function update(Request $request, AuditLog $audit): RedirectResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'edge.dns.manage');
        $zones = CloudflareZone::query()->where('organization_id', $organizationId)->pluck('name')->map(fn ($zone) => GeneratedDomains::CLOUDFLARE.$zone)->all();
        $choices = ['default', GeneratedDomains::OFF, ...GeneratedDomains::PROVIDERS, ...$zones];
        $default = $this->generated->defaultSuffix();

        if ($default !== null) {
            $choices[] = $default;
        }

        $provider = $request->validate(['provider' => ['required', 'string', Rule::in(array_values(array_unique($choices)))]])['provider'];
        OrganizationSetting::for($organizationId)->forceFill(['generated_domain_provider' => $provider === 'default' ? null : $provider])->save();
        $audit->record('edge.generated_domains_updated', 'organization', $organizationId, ['provider' => $provider], $organizationId);

        return back()->with('success', match ($provider) {
            GeneratedDomains::OFF => 'Generated domains are off. New services need a test domain or your own domain.',
            'default' => 'Generated domains use the server default.',
            default => str_starts_with($provider, GeneratedDomains::CLOUDFLARE)
                ? 'New services get names under '.substr($provider, strlen(GeneratedDomains::CLOUDFLARE)).'; Falak creates their DNS records in Cloudflare.'
                : "Generated domains now use {$provider}.",
        });
    }
}
