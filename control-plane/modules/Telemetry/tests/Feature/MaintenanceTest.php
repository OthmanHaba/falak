<?php

use Falak\Identity\Contracts\Role;
use Falak\Identity\Events\OrganizationDeleted;
use Falak\Telemetry\Application\Jobs\DispatchPendingTelemetry;
use Falak\Telemetry\Contracts\TelemetryConfigurator;
use Falak\Telemetry\Domain\Models\GrafanaState;
use Falak\Telemetry\Domain\Models\PendingConfiguration;
use Falak\Telemetry\Domain\Models\TelemetrySettings;
use Illuminate\Support\Facades\Http;

it('retries pending configurations for servers without an agent, then drops them', function () {
    PendingConfiguration::query()->create(['server_id' => '01jxxx0000000000000000000a', 'organization_id' => '01jorg0000000000000000000a', 'due_at' => now()->subSecond()]);

    (new DispatchPendingTelemetry)->handle(app(TelemetryConfigurator::class));
    $pending = PendingConfiguration::query()->findOrFail('01jxxx0000000000000000000a');
    expect($pending->attempts)->toBe(1)->and($pending->due_at->isFuture())->toBeTrue();

    $pending->forceFill(['attempts' => DispatchPendingTelemetry::MAX_ATTEMPTS - 1, 'due_at' => now()->subSecond()])->save();
    (new DispatchPendingTelemetry)->handle(app(TelemetryConfigurator::class));

    expect(PendingConfiguration::query()->count())->toBe(0);
});

it('forgets an organization\'s telemetry state when it is deleted', function () {
    TelemetrySettings::query()->create(['organization_id' => '01jorg0000000000000000000a', 'environment' => 'x']);
    GrafanaState::query()->create(['organization_id' => '01jorg0000000000000000000a', 'folder_uid' => 'f']);

    OrganizationDeleted::dispatch('01jorg0000000000000000000a');

    expect(TelemetrySettings::query()->count())->toBe(0)->and(GrafanaState::query()->count())->toBe(0);
});

it('provisions Grafana from the console', function () {
    Http::preventStrayRequests();
    [, $organization] = actingAsMember(Role::Owner);

    $this->artisan('telemetry:grafana:provision')->expectsOutputToContain('not configured')->assertFailed();

    config(['telemetry.grafana.url' => 'http://grafana:3000', 'telemetry.grafana.token' => 't']);
    Http::fake(['grafana:3000/*' => Http::response(['id' => 1, 'version' => 1, 'uid' => 'x', 'title' => $organization->name])]);

    $this->artisan('telemetry:grafana:provision', ['--organization' => [$organization->id]])
        ->expectsOutputToContain("{$organization->id}: ")
        ->assertSuccessful();

    // Without --organization: every previously provisioned organization.
    $this->artisan('telemetry:grafana:provision')->expectsOutputToContain($organization->id)->assertSuccessful();
    expect(GrafanaState::query()->findOrFail($organization->id)->provisioned_at)->not->toBeNull();
});
