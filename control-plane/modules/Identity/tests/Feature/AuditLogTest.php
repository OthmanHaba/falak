<?php

use Inertia\Testing\AssertableInertia as Assert;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Contracts\Role;
use Kiln\Identity\Domain\Models\AuditEntry;

it('denies the audit log to roles without audit.view', function (Role $role) {
    [, $organization] = memberOf();
    [$user] = memberOf($organization, $role);

    $this->actingAs($user)->get('/settings/audit-log')->assertForbidden();
})->with([Role::Developer, Role::Viewer]);

it('shows only the current organization entries, newest first, with filters', function () {
    [$admin, $organization] = actingAsMember(Role::Admin);
    [, $other] = memberOf();
    $audit = app(AuditLog::class);
    $audit->record('server.created', 'server', 'srv1', ['name' => 'web-1'], $organization->id);
    $audit->record('server.deleted', 'server', 'srv1', [], $organization->id);
    $audit->record('site.created', 'site', 'site1', [], $organization->id);
    $audit->record('server.created', 'server', 'srvX', [], $other->id);

    $this->get('/settings/audit-log')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Identity/organizations/audit-log', false)
        ->where('entries.total', AuditEntry::query()->where('organization_id', $organization->id)->count())
        ->where('actions', fn ($actions) => collect($actions)->contains('server.created') && collect($actions)->contains('site.created')));

    $this->get('/settings/audit-log?action=server.')->assertInertia(fn (Assert $page) => $page
        ->where('entries.total', 2)
        ->where('entries.data.0.action', 'server.deleted')
        ->where('filters', ['action' => 'server.']));

    $this->get('/settings/audit-log?subject=srv1&action=server.created')->assertInertia(fn (Assert $page) => $page
        ->where('entries.total', 1)
        ->where('entries.data.0.context', ['name' => 'web-1'])
        ->where('entries.data.0.actor_name', $admin->name)
        ->where('entries.data.0.actor_type', 'user'));

    $this->get('/settings/audit-log?actor='.$admin->id.'&from='.now()->subDay()->toDateString())
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', fn ($total) => $total >= 3));

    $this->get('/settings/audit-log?from='.now()->addDay()->toDateString())->assertInertia(fn (Assert $page) => $page->where('entries.total', 0));
});

it('records the actor, request metadata and redacts sensitive context', function () {
    [$user, $organization] = actingAsMember();

    app(AuditLog::class)->record('provider.created', 'credential', 'c1', [
        'name' => 'hetzner',
        'password' => 'hunter2',
        'nested' => ['api_key' => 'sk_live', 'region' => 'fsn1', 'Authorization' => 'Bearer x'],
        'access_token' => 't',
    ]);

    $entry = AuditEntry::query()->where('action', 'provider.created')->sole();
    expect($entry->organization_id)->toBe($organization->id)
        ->and($entry->actor_type)->toBe('user')
        ->and($entry->actor_id)->toBe($user->id)
        ->and($entry->context)->toBe([
            'name' => 'hetzner',
            'password' => '[redacted]',
            'nested' => ['api_key' => '[redacted]', 'region' => 'fsn1', 'Authorization' => '[redacted]'],
            'access_token' => '[redacted]',
        ]);
});

it('records system actors outside a request user and honours explicit organizations', function () {
    [, $organization] = memberOf();
    app(CurrentOrganization::class);

    app(AuditLog::class)->record('fleet.agent.offline', 'agent', 'a1', organizationId: $organization->id);

    $entry = AuditEntry::query()->where('action', 'fleet.agent.offline')->sole();
    expect($entry->actor_type)->toBe('system')
        ->and($entry->actor_name)->toBe('System')
        ->and($entry->organization_id)->toBe($organization->id);
});
