<?php

namespace Kiln\Sites\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Kernel\Http\Controller;
use Kiln\Sites\Domain\Models\OrganizationSettings;
use Kiln\Sites\Domain\Models\Site;

/**
 * Organization settings → Compose: the compose policy (docs/COMPOSE_TEMPLATES.md §1.3).
 */
final class ComposePolicyController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
    ) {}

    public function show(Request $request): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'sites.view');

        return Inertia::render('Sites/ComposePolicy', [
            'policy' => [
                'allow_privileged' => OrganizationSettings::for($organizationId)->allow_privileged_compose,
                'safe_capabilities' => array_values((array) config('sites.compose.safe_capabilities', [])),
            ],
            'compose_sites' => Site::query()->where('organization_id', $organizationId)->where('runtime', 'compose')->count(),
            'can' => ['manage' => $this->access->can($request->user(), $organizationId, 'sites.compose.policy')],
        ]);
    }

    public function update(Request $request, AuditLog $audit): RedirectResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'sites.compose.policy');
        $data = $request->validate(['allow_privileged' => ['required', 'boolean']]);

        $settings = OrganizationSettings::for($organizationId);
        $settings->forceFill(['allow_privileged_compose' => (bool) $data['allow_privileged']])->save();
        $audit->record('sites.compose_policy_updated', 'organization', $organizationId, ['allow_privileged' => (bool) $data['allow_privileged']], $organizationId);

        return back()->with('success', $data['allow_privileged'] ? 'Privileged compose files are allowed.' : 'Privileged compose files are blocked.');
    }
}
