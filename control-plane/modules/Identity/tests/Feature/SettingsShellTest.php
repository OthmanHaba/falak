<?php

use Inertia\Testing\AssertableInertia as Assert;
use Falak\Identity\Contracts\Role;

it('serves organization settings at the canonical /settings/{section} URLs', function (string $url, string $component) {
    actingAsMember(Role::Owner);

    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component($component, false));
})->with([
    ['/settings/profile', 'Identity/settings/profile'],
    ['/settings/password', 'Identity/settings/password'],
    ['/settings/two-factor', 'Identity/settings/two-factor'],
    ['/settings/appearance', 'Identity/settings/appearance'],
    ['/settings/api-tokens', 'Identity/settings/api-tokens'],
    ['/settings/organization', 'Identity/organizations/settings'],
    ['/settings/members', 'Identity/organizations/members'],
    ['/settings/teams', 'Identity/organizations/teams'],
    ['/settings/audit-log', 'Identity/organizations/audit-log'],
]);

it('permanently redirects the legacy /organization/* pages, keeping the query string', function () {
    actingAsMember(Role::Owner);

    $this->get('/organization/settings')->assertStatus(301)->assertRedirect('/settings/organization');
    $this->get('/organization/members')->assertStatus(301)->assertRedirect('/settings/members');
    $this->get('/organization/teams')->assertStatus(301)->assertRedirect('/settings/teams');
    $this->get('/organization/audit-log?action=member.removed&page=2')
        ->assertStatus(301)
        ->assertRedirect('/settings/audit-log?action=member.removed&page=2');
});

it('generates the canonical URLs from the existing route names', function () {
    expect(route('organization.settings', absolute: false))->toBe('/settings/organization')
        ->and(route('organization.members.index', absolute: false))->toBe('/settings/members')
        ->and(route('organization.teams.index', absolute: false))->toBe('/settings/teams')
        ->and(route('organization.audit-log', absolute: false))->toBe('/settings/audit-log');
});

it('renders the theme from the appearance cookie on first paint', function () {
    actingAsMember(Role::Owner);

    $this->get('/settings/profile')->assertSee('class="dark"', false);
    $this->withUnencryptedCookie('appearance', 'light')->get('/settings/profile')->assertSee('class="light"', false);
    $this->withUnencryptedCookie('appearance', 'system')->get('/settings/profile')->assertSee('data-appearance="system"', false);
    $this->withUnencryptedCookie('appearance', '<script>')->get('/settings/profile')->assertSee('data-appearance="dark"', false);
});
