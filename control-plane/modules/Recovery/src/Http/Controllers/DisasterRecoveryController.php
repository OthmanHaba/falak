<?php

namespace Falak\Recovery\Http\Controllers;

use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Kernel\Http\Controller;
use Falak\Recovery\Application\ControlPlaneNotice;
use Falak\Recovery\Application\ControlPlaneStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Disaster recovery: the control plane's backups as falak-ctl reports them (state/dr.json). It is set up
 * on the host (falak-ctl dr setup); the panel never receives the bucket keys or the DR passphrase, so it shows the
 * commands instead of a form.
 */
final class DisasterRecoveryController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly ControlPlaneNotice $notice,
    ) {}

    public function show(Request $request, ControlPlaneStatus $status): Response
    {
        $this->authorizeOperator($request);
        $dismissed = $this->notice->dismissedUntil((string) $request->user()?->getAuthIdentifier());

        return Inertia::render('Recovery/Settings', [
            'status' => $status->toArray(),
            'dismissed_until' => $dismissed?->isFuture() ? $dismissed->toIso8601String() : null,
            'commands' => [
                'setup' => 'sudo falak-ctl dr setup',
                'status' => 'sudo falak-ctl dr status',
                'backup' => 'sudo falak-ctl backup --upload',
                'drill' => 'sudo falak-ctl dr drill',
                'restore' => 'curl -fsSL https://falak.sh/install.sh | sudo bash -s -- --domain '.parse_url((string) config('app.url'), PHP_URL_HOST).' --email you@example.com --restore-from s3://latest',
            ],
        ]);
    }

    public function dismiss(Request $request): RedirectResponse
    {
        $this->authorizeOperator($request);
        $this->notice->dismiss((string) $request->user()?->getAuthIdentifier());

        return back();
    }

    private function authorizeOperator(Request $request): void
    {
        $organizationId = $this->organization->requireId();

        // Other organizations of a shared install don't see the control plane's settings at all.
        abort_if($organizationId !== $this->notice->operatorOrganizationId(), 404);
        abort_unless($this->notice->canManage($request->user(), $organizationId), 403);
    }
}
