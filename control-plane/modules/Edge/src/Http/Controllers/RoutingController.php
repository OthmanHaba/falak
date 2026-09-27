<?php

namespace Kiln\Edge\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Kiln\Edge\Application\Actions\AddRedirect;
use Kiln\Edge\Application\Actions\AddSecurityRule;
use Kiln\Edge\Application\Actions\DeleteSiteRule;
use Kiln\Edge\Application\Actions\SaveHeader;
use Kiln\Edge\Application\Actions\UpdateSiteSettings;
use Kiln\Edge\Domain\Models\Header;
use Kiln\Edge\Domain\Models\LoadBalancer;
use Kiln\Edge\Domain\Models\Redirect;
use Kiln\Edge\Domain\Models\SecurityRule;
use Kiln\Edge\Domain\Models\SiteSetting;
use Kiln\Edge\Http\Rules\Cidr;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Kernel\Http\Controller;

final class RoutingController extends Controller
{
    use ResolvesSite;

    /** Headers Caddy / the edge manages itself. */
    private const RESERVED_HEADERS = ['content-length', 'transfer-encoding', 'connection', 'host', 'location', 'set-cookie', 'upgrade'];

    public function __construct(private readonly OrganizationAccess $access) {}

    /** JSON for the Settings tab's Networking section (redirects, basic auth, headers, access & limits). */
    public function index(Request $request, string $site): JsonResponse|RedirectResponse
    {
        $siteData = $this->site($request, $site);

        if (! $this->wantsPanelJson($request)) {
            return $this->toNetworking($siteData);
        }

        $settings = SiteSetting::for($siteData->id);

        return response()->json(['data' => [
            'redirects' => Redirect::query()->where('site_id', $siteData->id)->orderBy('position')->get(['id', 'from', 'to', 'status']),
            'rules' => SecurityRule::query()->where('site_id', $siteData->id)->orderBy('path')->orderBy('username')->get()
                ->map(fn (SecurityRule $rule) => ['id' => $rule->id, 'name' => $rule->name, 'path' => $rule->path, 'username' => $rule->username])->values(),
            'headers' => Header::query()->where('site_id', $siteData->id)->orderBy('name')->get(['id', 'name', 'value']),
            'settings' => [
                'allow_ips' => $settings->allow_ips,
                'deny_ips' => $settings->deny_ips,
                'max_body_bytes' => $settings->max_body_bytes,
                'encode' => $settings->encode,
            ],
            'behindLoadBalancer' => LoadBalancer::query()->where('site_id', $siteData->id)->exists(),
            'can' => ['manage' => $this->access->can($request->user(), $siteData->organizationId, 'edge.manage')],
        ]]);
    }

    public function storeRedirect(Request $request, string $site, AddRedirect $add): RedirectResponse
    {
        $siteData = $this->site($request, $site, 'edge.manage');

        $data = $request->validate([
            'from' => ['required', 'string', 'max:500', 'regex:#^/\S*$#', Rule::unique('edge_redirects')->where('site_id', $siteData->id)],
            'to' => ['required', 'string', 'max:2000', 'regex:#^(https?://\S+|/\S*)$#'],
            'status' => ['required', 'integer', Rule::in(Redirect::STATUSES)],
        ], ['from.regex' => 'Start the path with / (wildcards like /blog/* are allowed).', 'to.regex' => 'Use an absolute URL or a path starting with /.']);

        $add($siteData, $data['from'], $data['to'], (int) $data['status']);

        return back();
    }

    public function destroyRedirect(Request $request, string $site, string $redirect, DeleteSiteRule $delete): RedirectResponse
    {
        $siteData = $this->site($request, $site, 'edge.manage');
        $delete(Redirect::query()->where('site_id', $siteData->id)->findOrFail($redirect), $siteData->organizationId);

        return back();
    }

    public function storeRule(Request $request, string $site, AddSecurityRule $add): RedirectResponse
    {
        $siteData = $this->site($request, $site, 'edge.manage');

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'path' => ['nullable', 'string', 'max:500', 'regex:#^/\S*$#'],
            'username' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._@-]+$/'],
            'password' => ['required', 'string', 'min:8', 'max:200'],
        ], ['path.regex' => 'Start the path with / (e.g. /admin/*).']);

        $path = $data['path'] ?? null;
        // "/" and "/*" protect the whole site.
        $path = in_array($path, [null, '', '/', '/*'], true) ? null : $path;

        $exists = SecurityRule::query()->where('site_id', $siteData->id)->where('username', $data['username'])
            ->when($path === null, fn ($q) => $q->whereNull('path'), fn ($q) => $q->where('path', $path))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['username' => 'This user already protects that path.']);
        }

        $add($siteData, $data['name'] ?? null, $path, $data['username'], $data['password']);

        return back();
    }

    public function destroyRule(Request $request, string $site, string $rule, DeleteSiteRule $delete): RedirectResponse
    {
        $siteData = $this->site($request, $site, 'edge.manage');
        $delete(SecurityRule::query()->where('site_id', $siteData->id)->findOrFail($rule), $siteData->organizationId);

        return back();
    }

    public function storeHeader(Request $request, string $site, SaveHeader $save): RedirectResponse
    {
        $siteData = $this->site($request, $site, 'edge.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9-]+$/'],
            'value' => ['required', 'string', 'max:2000', 'regex:/^[^\r\n]*$/'],
        ]);

        if (in_array(strtolower($data['name']), self::RESERVED_HEADERS, true)) {
            throw ValidationException::withMessages(['name' => 'This header is managed by the edge.']);
        }

        $save($siteData, $data['name'], $data['value']);

        return back();
    }

    public function destroyHeader(Request $request, string $site, string $header, DeleteSiteRule $delete): RedirectResponse
    {
        $siteData = $this->site($request, $site, 'edge.manage');
        $delete(Header::query()->where('site_id', $siteData->id)->findOrFail($header), $siteData->organizationId);

        return back();
    }

    public function updateSettings(Request $request, string $site, UpdateSiteSettings $update): RedirectResponse
    {
        $siteData = $this->site($request, $site, 'edge.manage');

        $data = $request->validate([
            'allow_ips' => ['present', 'array', 'max:200'],
            'allow_ips.*' => ['string', new Cidr],
            'deny_ips' => ['present', 'array', 'max:200'],
            'deny_ips.*' => ['string', new Cidr],
            'max_body_bytes' => ['nullable', 'integer', 'min:0', 'max:'.(10 * 1024 ** 3)],
            'encode' => ['required', 'boolean'],
        ]);

        $update(
            $siteData,
            array_values($data['allow_ips']),
            array_values($data['deny_ips']),
            isset($data['max_body_bytes']) ? (int) $data['max_body_bytes'] : null,
            (bool) $data['encode'],
        );

        return back();
    }
}
