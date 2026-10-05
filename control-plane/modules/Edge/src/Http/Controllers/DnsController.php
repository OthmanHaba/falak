<?php

namespace Falak\Edge\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Falak\Edge\Application\DomainOptions;
use Falak\Edge\Contracts\DnsCheck;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;

/**
 * The domain picker's data (web session and API): GET domains/options and GET dns/check.
 */
final class DnsController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly DomainOptions $options,
    ) {}

    public function options(Request $request): JsonResponse
    {
        $organizationId = $this->viewer($request);
        [$serverIds] = $this->options->servers($organizationId, $this->serverIds($request), $request->string('site')->toString() ?: null);

        return response()->json(['data' => $this->options->for($organizationId, $serverIds, $request->string('site')->toString() ?: null)]);
    }

    public function check(Request $request, DnsCheck $dns): JsonResponse
    {
        $organizationId = $this->viewer($request);
        $request->validate([
            'name' => ['required', 'string', 'max:253'],
            'label' => ['nullable', 'string', 'max:63'],
            'tls' => ['nullable', 'boolean'],
        ]);
        $siteId = $request->string('site')->toString() ?: null;
        [$serverIds, $site] = $this->options->servers($organizationId, $this->serverIds($request), $siteId);
        $label = $request->string('label')->toString() ?: $site?->slug;

        $result = $dns->check($organizationId, $request->string('name')->toString(), $serverIds, $site?->id, $label, $site !== null && $request->boolean('tls'));

        return response()->json(['data' => $result->toArray()]);
    }

    private function viewer(Request $request): string
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'edge.view');

        return $organizationId;
    }

    /**
     * server_ids[]=…, server[]=…, or server=a,b (API).
     *
     * @return list<string>
     */
    private function serverIds(Request $request): array
    {
        $ids = [];

        foreach (['server_ids', 'server'] as $key) {
            foreach ((array) $request->query($key, []) as $value) {
                foreach (explode(',', (string) $value) as $id) {
                    if (preg_match('/^[0-9a-z]{26}$/i', trim($id)) === 1) {
                        $ids[] = strtolower(trim($id));
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
