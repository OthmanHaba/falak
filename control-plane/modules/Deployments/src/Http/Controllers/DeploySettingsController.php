<?php

namespace Kiln\Deployments\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Kiln\Deployments\Application\Actions\UpdateDeploySettings;
use Kiln\Deployments\Domain\Enums\Strategy;
use Kiln\Deployments\Domain\Models\SiteSettings;
use Kiln\Deployments\Domain\Policies\DeploymentPermissions;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Kernel\Http\Controller;
use Kiln\Sites\Contracts\SiteDeploySettings;
use Kiln\Sites\Contracts\SiteHeaders;

final class DeploySettingsController extends Controller
{
    use ResolvesSites;

    public function show(Request $request, string $site, SiteHeaders $headers): Response
    {
        $data = $this->site($request->user(), $site);
        $settings = SiteSettings::for($data);
        $manage = $this->can($request->user(), $data, DeploymentPermissions::MANAGE);

        return Inertia::render('Deployments/Settings', [
            'site' => $headers->for($data->id),
            'settings' => [
                'strategy' => $settings->effectiveStrategy($data)->value,
                'batch_size' => $settings->batch_size,
                'keep_releases' => $settings->keep_releases,
                'health_enabled' => $settings->health_enabled,
                'health_path' => $settings->health_path,
                'health_status' => $settings->health_status,
                'health_timeout_s' => $settings->health_timeout_s,
                'health_retries' => $settings->health_retries,
                'health_retry_delay_s' => $settings->health_retry_delay_s,
            ],
            'defaultHealthPath' => $data->healthCheckPath ?: '/',
            'strategies' => array_map(fn (Strategy $s) => ['value' => $s->value, 'label' => $s->label(), 'description' => $s->description()], Strategy::for($data->runtime)),
            'pushToDeploy' => $data->pushToDeploy,
            'hasRepository' => $data->repository !== null,
            'branch' => $data->branch,
            'hookUrl' => $manage && $settings->hook_token ? $this->hookUrl($settings->hook_token) : null,
            'hasHook' => $settings->hook_token_hash !== null,
            'can' => ['manage' => $manage],
        ]);
    }

    public function update(Request $request, string $site, UpdateDeploySettings $update): RedirectResponse
    {
        $data = $this->site($request->user(), $site, DeploymentPermissions::MANAGE);
        $input = $request->validate([
            'strategy' => ['required', Rule::enum(Strategy::class)],
            'batch_size' => ['required', 'integer', 'min:1', 'max:100'],
            'keep_releases' => ['required', 'integer', 'min:1', 'max:50'],
            'health_enabled' => ['required', 'boolean'],
            'health_path' => ['nullable', 'string', 'max:255', 'regex:/^\/[^\s]*$/'],
            'health_status' => ['required', 'integer', 'min:100', 'max:599'],
            'health_timeout_s' => ['required', 'integer', 'min:1', 'max:120'],
            'health_retries' => ['required', 'integer', 'min:1', 'max:30'],
            'health_retry_delay_s' => ['required', 'integer', 'min:0', 'max:300'],
        ]);

        $update($data, $input, (string) $request->user()?->getAuthIdentifier());

        return back();
    }

    public function pushToDeploy(Request $request, string $site, SiteDeploySettings $sites): RedirectResponse
    {
        $data = $this->site($request->user(), $site, DeploymentPermissions::MANAGE);
        $input = $request->validate(['enabled' => ['required', 'boolean']]);
        $warnings = $sites->setPushToDeploy($data->id, (bool) $input['enabled'], (string) $request->user()?->getAuthIdentifier());

        return back()->with('warnings', $warnings);
    }

    public function rotateHook(Request $request, string $site, AuditLog $audit): RedirectResponse
    {
        $data = $this->site($request->user(), $site, DeploymentPermissions::MANAGE);
        SiteSettings::for($data)->rotateHookToken();
        $audit->record('deployments.hook_rotated', 'site', $data->id, [], $data->organizationId);

        return back();
    }

    public function disableHook(Request $request, string $site, AuditLog $audit): RedirectResponse
    {
        $data = $this->site($request->user(), $site, DeploymentPermissions::MANAGE);
        SiteSettings::for($data)->forceFill(['hook_token' => null, 'hook_token_hash' => null])->save();
        $audit->record('deployments.hook_disabled', 'site', $data->id, [], $data->organizationId);

        return back();
    }

    private function hookUrl(string $token): string
    {
        return rtrim((string) (config('fleet.panel_url') ?: config('app.url')), '/')."/api/deploy/{$token}";
    }
}
