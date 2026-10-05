<?php

/*
| Organization settings sections moved into the /settings/{section} shell (docs/UI_DESIGN.md §3): each page renders
| there, and its pre-redesign URL permanently redirects (query string kept) so bookmarks, CLI `open` and alert links
| keep working.
*/

use Inertia\Testing\AssertableInertia as Assert;
use Falak\Identity\Contracts\Role;

dataset('settings sections', [
    'source control' => ['/source-control', '/settings/source-control', 'SourceControl/Index'],
    'cloud providers' => ['/providers', '/settings/cloud-providers', 'Providers/Index'],
    'backup storage' => ['/databases/storage', '/settings/storage', 'Databases/Storage'],
    'builders' => ['/builds/builders', '/settings/builders', 'Builds/Builders'],
    'alert channels' => ['/alerting/channels', '/settings/alert-channels', 'Alerting/Channels'],
    'alert rules' => ['/alerting/rules', '/settings/alert-rules', 'Alerting/Rules'],
    'observability' => ['/telemetry/settings', '/settings/observability', 'Telemetry/Settings'],
    'recipes' => ['/recipes', '/settings/recipes', 'Recipes/Index'],
]);

it('renders the section inside the settings shell', function (string $legacy, string $url, string $component) {
    actingAsMember(Role::Owner);

    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component($component, false));

    [$module, $page] = explode('/', $component, 2);
    expect(base_path("modules/{$module}/resources/js/pages/{$page}.tsx"))->toBeFile();
})->with('settings sections');

it('permanently redirects the legacy URL and keeps the query string', function (string $legacy, string $url) {
    actingAsMember(Role::Owner);

    $this->get($legacy)->assertStatus(301)->assertRedirect($url);
    $this->get("{$legacy}?add=1&tab=x")->assertStatus(301)->assertRedirect("{$url}?add=1&tab=x");
})->with('settings sections');

it('keeps legacy URLs behind authentication', function (string $legacy) {
    $this->get($legacy)->assertRedirect('/login');
})->with('settings sections');

it('links alert messages to the new channels page', function () {
    expect(file_get_contents(base_path('modules/Alerting/src/Application/AlertMessage.php')))->toContain("url('/settings/alert-channels')");
});
