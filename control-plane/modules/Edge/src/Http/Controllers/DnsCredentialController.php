<?php

namespace Kiln\Edge\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Kiln\Edge\Application\Actions\CreateDnsCredential;
use Kiln\Edge\Application\Actions\DeleteDnsCredential;
use Kiln\Edge\Domain\Models\DnsCredential;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Kernel\Http\Controller;

final class DnsCredentialController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
    ) {}

    public function store(Request $request, CreateDnsCredential $create): RedirectResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'edge.dns.manage');

        $data = $request->validate([
            'provider' => ['required', Rule::in(array_keys(DnsCredential::PROVIDERS))],
            'name' => ['required', 'string', 'max:100', Rule::unique('edge_dns_credentials')->where('organization_id', $organizationId)],
            'api_token' => ['required', 'string', 'min:20', 'max:500'],
        ]);

        $create($organizationId, $data['provider'], $data['name'], $data['api_token'], $request->user()?->getAuthIdentifier());

        return back();
    }

    public function destroy(Request $request, string $credential, DeleteDnsCredential $delete): RedirectResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'edge.dns.manage');

        $delete(DnsCredential::query()->where('organization_id', $organizationId)->findOrFail($credential));

        return back();
    }
}
