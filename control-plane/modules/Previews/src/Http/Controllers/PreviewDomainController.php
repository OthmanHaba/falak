<?php

namespace Falak\Previews\Http\Controllers;

use Falak\Edge\Contracts\PreviewDomains;
use Falak\Kernel\Http\Controller;
use Falak\Previews\Application\InstanceOperator;
use Falak\Servers\Contracts\ServerDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Previews: the instance's preview domain, its DNS credential and edge server. Only the operator
 * organization's owners and admins change it; other members see whether previews are available.
 */
final class PreviewDomainController extends Controller
{
    public function __construct(private readonly InstanceOperator $operator) {}

    public function show(Request $request, PreviewDomains $domains, ServerDirectory $servers): Response
    {
        $admin = $this->operator->isAdmin($request->user());
        $operator = $this->operator->organizationId();
        $settings = $domains->settings();

        return Inertia::render('Previews/Domain', [
            'settings' => $settings !== null ? [
                'domain' => $settings->domain,
                'server_id' => $admin ? $settings->serverId : null,
                'dns_credential_id' => $admin ? $settings->dnsCredentialId : null,
                'managed_dns' => $settings->managedDns,
                'status' => $settings->status,
                'error' => $admin ? $settings->error : null,
            ] : null,
            'can' => ['manage' => $admin],
            'servers' => $admin && $operator !== null ? array_map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'ipv4' => $s->ipv4], $servers->forOrganization($operator, activeOnly: true)) : [],
            'dns_credentials' => $admin && $operator !== null ? $domains->credentials($operator) : [],
        ]);
    }

    public function update(Request $request, PreviewDomains $domains): RedirectResponse
    {
        abort_unless($this->operator->isAdmin($request->user()), 403);

        $data = $request->validate([
            'domain' => ['required', 'string', 'max:190'],
            'server_id' => ['required', 'string', 'size:26'],
            'dns_credential_id' => ['nullable', 'string', 'size:26'],
        ]);

        $settings = $domains->configure((string) $this->operator->organizationId(), $data['domain'], $data['dns_credential_id'] ?? null, $data['server_id'], $request->user()?->getAuthIdentifier());

        return back()->with($settings->status === 'error' ? 'error' : 'success', $settings->status === 'error' ? "Saved, but the DNS record failed: {$settings->error}" : 'Preview domain saved.');
    }

    public function destroy(Request $request, PreviewDomains $domains): RedirectResponse
    {
        abort_unless($this->operator->isAdmin($request->user()), 403);
        $domains->clear();

        return back()->with('success', 'Previews are off on this Falak.');
    }
}
