<?php

namespace Falak\Edge\Http\Controllers;

use Falak\Edge\Application\Actions\ConfigureLoadBalancer;
use Falak\Edge\Application\Actions\RemoveLoadBalancer;
use Falak\Edge\Domain\Enums\LbPolicy;
use Falak\Edge\Domain\Models\LoadBalancer;
use Falak\Kernel\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class LoadBalancerController extends Controller
{
    use ResolvesSite;

    public function update(Request $request, string $site, ConfigureLoadBalancer $configure): RedirectResponse
    {
        $siteData = $this->site($request, $site, 'edge.manage');

        $data = $request->validate([
            'server_id' => ['required', 'string', 'max:26'],
            'policy' => ['required', Rule::enum(LbPolicy::class)],
            'health_uri' => ['nullable', 'string', 'max:500', 'regex:#^/\S*$#'],
            'backend_port' => ['nullable', 'integer', 'between:1,65535'],
            'weights' => ['nullable', 'array'],
            'weights.*' => ['integer', 'between:1,'.LoadBalancer::MAX_WEIGHT],
        ]);

        $configure(
            $siteData,
            $data['server_id'],
            LbPolicy::from($data['policy']),
            $data['health_uri'] ?? null,
            (int) ($data['backend_port'] ?? 80),
            array_map('intval', (array) ($data['weights'] ?? [])),
        );

        return back();
    }

    public function destroy(Request $request, string $site, RemoveLoadBalancer $remove): RedirectResponse
    {
        $siteData = $this->site($request, $site, 'edge.manage');

        $remove(LoadBalancer::query()->where('site_id', $siteData->id)->firstOrFail());

        return back();
    }
}
