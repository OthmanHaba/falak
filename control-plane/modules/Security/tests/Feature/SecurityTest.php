<?php

use Falak\Databases\Contracts\Data\DatabaseBackupPosture;
use Falak\Fleet\Events\CommandFinished;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\AuditEntry;
use Falak\Network\Domain\Enums\RuleProtocol;
use Falak\Network\Domain\Models\FirewallRule;
use Falak\Security\Application\Actions\StartAudit;
use Falak\Security\Application\Jobs\RunDueAudits;
use Falak\Security\Domain\Enums\AuditStatus;
use Falak\Security\Domain\Enums\FixStatus;
use Falak\Security\Domain\Models\Audit;
use Falak\Security\Domain\Models\FixRun;
use Falak\Security\Events\CriticalFindingDetected;
use Falak\Security\Events\SecurityScoreDropped;
use Falak\Security\Events\UnexpectedPortDetected;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Servers\Contracts\ServerStatus;
use Falak\Servers\Domain\Models\SshKey;
use Falak\Servers\Events\ServerProvisioned;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    $this->agents = FakeAgentGateway::install();
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
    $this->server = security_server($this->organization, ['name' => 'web-1']);
    security_backups([]);
});

it('dispatches a schema-valid audit with what the control plane expects', function () {
    $key = SshKey::query()->create(['organization_id' => $this->organization->id, 'name' => 'laptop', 'fingerprint' => 'SHA256:x',
        'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGkq1Qk0bJ0m7xYk6o8y2e8b5gKq0bJ8m1e3Yx5qZ8Vw othman@laptop']);
    $this->server->sshKeys()->attach($key->id, ['unix_user' => 'root']);
    FirewallRule::query()->create(['organization_id' => $this->organization->id, 'server_id' => $this->server->id, 'name' => 'App', 'action' => 'allow', 'protocol' => 'udp', 'port' => '8000-8100', 'position' => 1]);

    $this->post("/security/servers/{$this->server->id}/audit")->assertSessionHasNoErrors();

    $payload = $this->agents->last('security.audit', $this->server->id)['payload'];
    expect($payload['managed_keys'])->toBe(['falak' => [], 'root' => [$key->public_key]])
        ->and($payload['expected_ports'])->toContain('tcp/22', 'udp/8000-8100')
        ->and($payload['known_users'])->toBe(['falak'])
        ->and($payload['ssh_port'])->toBe(22)
        ->and(Audit::query()->sole()->status)->toBe(AuditStatus::Running);

    // One audit at a time.
    $this->post("/security/servers/{$this->server->id}/audit");
    expect($this->agents->dispatched('security.audit'))->toHaveCount(1)
        ->and(AuditEntry::query()->where('action', 'security.audit_requested')->count())->toBe(2);
});

it('scores an audit and shows the production ready badge only without high or critical failures', function () {
    $audit = security_audit($this->agents, $this->server, [
        security_check('ssh.password_authentication', 'fail', 'high', 'ssh.harden'),
        security_check('kernel.x', 'fail', 'low', 'kernel.sysctl', 'kernel'),
        security_check('accounts.sudo_nopasswd', 'warn', 'medium', area: 'accounts'),
        security_check('time.ntp'),
        security_check('accounts.unknown_users', 'info', 'info', area: 'accounts'),
        ['id' => 'BAD id', 'status' => 'fail', 'severity' => 'critical', 'area' => 'ssh'], // malformed: dropped
    ]);

    // 100 - 15 (high fail) - 2 (low fail) - 2 (medium warning)
    expect($audit->status)->toBe(AuditStatus::Completed)
        ->and($audit->score)->toBe(81)
        ->and($audit->production_ready)->toBeFalse()
        ->and($audit->counts)->toMatchArray(['fail' => 2, 'warn' => 1, 'pass' => 1, 'info' => 2, 'high' => 1, 'low' => 1, 'medium' => 1])
        ->and($audit->findings()->count())->toBe(6) // five agent checks + the backups check
        ->and($audit->findings()->where('check_id', 'backups.coverage')->value('status'))->toBe('info');

    $audit = security_audit($this->agents, $this->server, [security_check('ssh.password_authentication'), security_check('kernel.x', 'fail', 'medium', 'kernel.sysctl', 'kernel')]);
    expect($audit->score)->toBe(95)->and($audit->production_ready)->toBeTrue();

    $this->get("/servers/{$this->server->id}/security")->assertOk()->assertInertia(fn ($page) => $page
        ->component('Security/Server', false)
        ->where('audit.score', 95)
        ->where('audit.production_ready', true)
        ->has('history', 2)
        ->where('safeFixes', ['kernel.sysctl'])
        ->where('can.fix', true));
});

it('checks backups on the control plane', function () {
    security_backups([
        new DatabaseBackupPosture('d1', 'shop', 'i1', now()->subDay(), false, null),
        new DatabaseBackupPosture('d2', 'blog', 'i1', null, null, null),
        new DatabaseBackupPosture('d3', 'crm', 'i1', now()->subHours(2), true, now()->subDays(3)),
    ]);

    $audit = security_audit($this->agents, $this->server, [security_check('ssh.password_authentication')]);
    $findings = $audit->findings()->get()->keyBy('check_id');

    expect($findings['backups.coverage']->only('status', 'severity', 'evidence'))->toBe(['status' => 'fail', 'severity' => 'high', 'evidence' => 'No successful backup in 7 days: blog.'])
        ->and($findings['backups.encryption']->status)->toBe('warn')
        ->and($findings['backups.encryption']->evidence)->toContain('shop')
        ->and($findings['backups.drills']->evidence)->toBe('No passed restore drill in 30 days: shop.')
        ->and($audit->production_ready)->toBeFalse();
});

it('records a failed audit and an unreachable agent', function () {
    app(StartAudit::class)(app(ServerDirectory::class)->find($this->server->id), 'manual');
    $this->agents->fail($this->agents->last('security.audit')['handle'], 'timed out');
    expect(Audit::query()->sole()->only('status', 'error'))->toBe(['status' => AuditStatus::Failed, 'error' => 'timed out']);

    $this->agents->unavailable($this->server->id);
    $this->post("/security/servers/{$this->server->id}/audit");
    expect(Audit::query()->latest('id')->first()->error)->toBe('The server agent is not connected.');
});

it('audits after provisioning and every day', function () {
    ServerProvisioned::dispatch($this->server->id, $this->organization->id, 'web', 'web-1');
    expect($this->agents->dispatched('security.audit'))->toHaveCount(1);
    $this->agents->succeed($this->agents->last('security.audit')['handle'], ['checks' => [], 'duration_ms' => 1]);

    $idle = security_server($this->organization, ['status' => ServerStatus::Provisioning]);
    dispatch_sync(new RunDueAudits);
    expect($this->agents->dispatched('security.audit'))->toHaveCount(1); // audited within the day; the other is not active

    Carbon::setTestNow(now()->addHours(25));
    dispatch_sync(new RunDueAudits);
    expect($this->agents->dispatched('security.audit', $this->server->id))->toHaveCount(2)
        ->and($this->agents->dispatched('security.audit', $idle->id))->toHaveCount(0);

    // An audit that never answers is given up and the next run retries.
    Carbon::setTestNow(now()->addHours(25));
    dispatch_sync(new RunDueAudits);
    expect(Audit::query()->where('status', AuditStatus::Failed)->where('error', 'The agent did not answer.')->count())->toBe(1);

    $events = collect(app(Schedule::class)->events())->map->description;
    expect($events)->toContain('security:audits');
});

it('applies a fix, keeps its backup for undo and audits again', function () {
    security_audit($this->agents, $this->server, [security_check('kernel.x', 'fail', 'low', 'kernel.sysctl', 'kernel')]);

    $this->post("/security/servers/{$this->server->id}/fixes", ['fix_id' => 'kernel.sysctl'])->assertSessionHasNoErrors();

    $command = $this->agents->last('security.fix', $this->server->id);
    $run = FixRun::query()->sole();
    expect($command['payload'])->toBe(['fix_id' => 'kernel.sysctl'])
        ->and($run->status)->toBe(FixStatus::Applying)
        ->and($run->applied_by)->toBe($this->user->id);

    $this->agents->succeed($command['handle'], ['fix_id' => 'kernel.sysctl', 'changed' => true, 'backup_id' => '20261009T120000Z-1a2b3c4d', 'disruptive' => false, 'undoable' => true, 'message' => 'hardened']);

    $run->refresh();
    expect($run->status)->toBe(FixStatus::Applied)
        ->and($run->backup_id)->toBe('20261009T120000Z-1a2b3c4d')
        ->and($run->canUndo())->toBeTrue()
        ->and($this->agents->dispatched('security.audit'))->toHaveCount(2)
        ->and(AuditEntry::query()->whereIn('action', ['security.fix_requested', 'security.fix_applied'])->count())->toBe(2);

    // Undo within the window.
    $this->post("/security/servers/{$this->server->id}/fixes/{$run->id}/undo")->assertSessionHasNoErrors();
    $undo = $this->agents->last('security.undo');
    expect($undo['payload'])->toBe(['fix_id' => 'kernel.sysctl', 'backup_id' => '20261009T120000Z-1a2b3c4d']);
    $this->agents->succeed($undo['handle'], ['fix_id' => 'kernel.sysctl', 'backup_id' => '20261009T120000Z-1a2b3c4d', 'restored' => 2]);

    expect($run->refresh()->status)->toBe(FixStatus::Undone)
        ->and($run->undone_at)->not->toBeNull()
        ->and(AuditEntry::query()->whereIn('action', ['security.undo_requested', 'security.fix_undone'])->count())->toBe(2);
});

it('refuses undo after the window and lets a failed undo be retried', function () {
    security_audit($this->agents, $this->server, [security_check('time.ntp', 'fail', 'medium', 'time.sync', 'time')]);
    $this->post("/security/servers/{$this->server->id}/fixes", ['fix_id' => 'time.sync']);
    $this->agents->succeed($this->agents->last('security.fix')['handle'], ['fix_id' => 'time.sync', 'changed' => true, 'backup_id' => '20261009T120000Z-00000001', 'disruptive' => false, 'undoable' => true, 'message' => 'ok']);
    $run = FixRun::query()->sole();

    $this->post("/security/servers/{$this->server->id}/fixes/{$run->id}/undo");
    $this->agents->fail($this->agents->last('security.undo')['handle'], 'timedatectl failed');
    expect($run->refresh()->status)->toBe(FixStatus::Applied)->and($run->error)->toBe('Undo failed: timedatectl failed')->and($run->canUndo())->toBeTrue();

    Carbon::setTestNow(now()->addDays(7)->addMinute());
    $this->post("/security/servers/{$this->server->id}/fixes/{$run->id}/undo")->assertSessionHasErrors('fix');
    expect($this->agents->dispatched('security.undo'))->toHaveCount(1);
});

it('records unchanged and failed fixes', function () {
    security_audit($this->agents, $this->server, [security_check('a', 'fail', 'low', 'kernel.sysctl', 'kernel'), security_check('b', 'fail', 'medium', 'fail2ban.sshd', 'intrusion')]);
    $this->post("/security/servers/{$this->server->id}/fixes", ['fix_id' => 'kernel.sysctl']);
    $this->agents->succeed($this->agents->last('security.fix')['handle'], ['fix_id' => 'kernel.sysctl', 'changed' => false, 'disruptive' => false, 'undoable' => false, 'message' => 'already hardened']);
    $this->post("/security/servers/{$this->server->id}/fixes", ['fix_id' => 'fail2ban.sshd']);
    $this->agents->fail($this->agents->last('security.fix')['handle'], 'fail2ban-client -t: exit status 1 (rolled back)');

    expect(FixRun::query()->orderBy('id')->get()->map(fn ($r) => [$r->status, $r->canUndo()])->all())->toBe([[FixStatus::Unchanged, false], [FixStatus::Failed, false]])
        ->and(AuditEntry::query()->where('action', 'security.fix_failed')->count())->toBe(1);
});

it('needs a confirmation for disruptive fixes and only offers what the audit found', function () {
    security_audit($this->agents, $this->server, [security_check('ssh.password_authentication', 'fail', 'high', 'ssh.harden'), security_check('updates.reboot', 'warn', 'medium', 'updates.reboot', 'updates')]);

    $this->post("/security/servers/{$this->server->id}/fixes", ['fix_id' => 'ssh.harden'])->assertSessionHasErrors('confirm');
    $this->post("/security/servers/{$this->server->id}/fixes", ['fix_id' => 'kernel.sysctl'])->assertSessionHasErrors('fix_id');
    $this->post("/security/servers/{$this->server->id}/fixes", ['fix_id' => 'system.exec'])->assertSessionHasErrors('fix_id');
    $this->agents->assertNothingDispatched('security.fix');

    $this->post("/security/servers/{$this->server->id}/fixes", ['fix_id' => 'ssh.harden', 'confirm' => true])->assertSessionHasNoErrors();
    $this->post("/security/servers/{$this->server->id}/fixes", ['fix_id' => 'updates.reboot', 'confirm' => true, 'reboot_at' => '03:30'])->assertSessionHasNoErrors();
    $this->post("/security/servers/{$this->server->id}/fixes", ['fix_id' => 'ssh.harden', 'confirm' => true])->assertSessionHasErrors('fix_id'); // already running

    expect(array_map(fn ($c) => $c['payload'], $this->agents->dispatched('security.fix')))->toBe([['fix_id' => 'ssh.harden'], ['fix_id' => 'updates.reboot', 'reboot_at' => '03:30']])
        ->and(FixRun::query()->where('fix_id', 'ssh.harden')->value('disruptive'))->toBeTrue();
});

it('lets only admins fix, while developers and viewers see the report', function (Role $role) {
    security_audit($this->agents, $this->server, [security_check('a', 'fail', 'low', 'kernel.sysctl', 'kernel')]);
    [$member] = memberOf($this->organization, $role);
    $this->actingAs($member);

    $this->get("/servers/{$this->server->id}/security")->assertOk()->assertInertia(fn ($page) => $page->where('can.fix', false));
    $this->get('/security')->assertOk();
    $this->post("/security/servers/{$this->server->id}/fixes", ['fix_id' => 'kernel.sysctl'])->assertForbidden();
    $this->post("/security/servers/{$this->server->id}/fixes/safe")->assertForbidden();
    $this->post("/security/servers/{$this->server->id}/audit")->assertForbidden();
    $this->agents->assertNothingDispatched('security.fix');
})->with([Role::Developer, Role::Viewer]);

it('applies every safe fix in sequence, leaving disruptive ones alone', function () {
    security_audit($this->agents, $this->server, [
        security_check('ssh.password_authentication', 'fail', 'high', 'ssh.harden'),
        security_check('kernel.a', 'fail', 'low', 'kernel.sysctl', 'kernel'),
        security_check('kernel.b', 'fail', 'low', 'kernel.sysctl', 'kernel'),
        security_check('time.ntp', 'fail', 'medium', 'time.sync', 'time'),
        security_check('files.secret_permissions', 'fail', 'high', 'files.secret_permissions', 'files'),
    ]);

    $this->post("/security/servers/{$this->server->id}/fixes/safe")->assertSessionHasNoErrors();

    $runs = FixRun::query()->orderBy('position')->get();
    expect($runs->pluck('fix_id')->all())->toBe(['files.secret_permissions', 'kernel.sysctl', 'time.sync'])
        ->and($runs->pluck('status')->all())->toBe([FixStatus::Applying, FixStatus::Queued, FixStatus::Queued])
        ->and($this->agents->dispatched('security.fix'))->toHaveCount(1);

    foreach (['files.secret_permissions', 'kernel.sysctl', 'time.sync'] as $i => $id) {
        $command = $this->agents->last('security.fix');
        expect($command['payload']['fix_id'])->toBe($id);
        expect($this->agents->dispatched('security.audit'))->toHaveCount(1); // not before the batch is done
        $i === 1
            ? $this->agents->fail($command['handle'], 'sysctl rejected the settings')
            : $this->agents->succeed($command['handle'], ['fix_id' => $id, 'changed' => true, 'backup_id' => "20261009T120000Z-0000000{$i}", 'disruptive' => false, 'undoable' => true, 'message' => 'ok']);
    }

    expect(FixRun::query()->orderBy('position')->pluck('status')->all())->toBe([FixStatus::Applied, FixStatus::Failed, FixStatus::Applied])
        ->and($this->agents->dispatched('security.fix'))->toHaveCount(3)
        ->and($this->agents->dispatched('security.audit'))->toHaveCount(2)
        ->and(AuditEntry::query()->where('action', 'security.fix_all_safe')->count())->toBe(1);
});

it('closes an unexpected port with a Network deny rule, and undoes it', function () {
    Event::fake([UnexpectedPortDetected::class]);
    $this->get("/servers/{$this->server->id}/firewall"); // default rules
    security_audit($this->agents, $this->server, [
        security_check('firewall.port.tcp.8080', 'warn', 'low', 'firewall.close_port:tcp:8080', 'firewall', 'node listens on all interfaces'),
    ]);
    Event::assertDispatched(UnexpectedPortDetected::class, fn ($e) => $e->checkId === 'firewall.port.tcp.8080' && $e->serverName === 'web-1');

    $this->post("/security/servers/{$this->server->id}/fixes/safe")->assertSessionHasNoErrors();

    $rule = FirewallRule::query()->where('server_id', $this->server->id)->where('action', 'deny')->sole();
    $run = FixRun::query()->sole();
    expect($rule->only('protocol', 'port', 'source'))->toBe(['protocol' => RuleProtocol::Tcp, 'port' => '8080', 'source' => null])
        ->and($run->status)->toBe(FixStatus::Applied)
        ->and($run->backup_id)->toBe($rule->id)
        ->and(collect($this->agents->last('net.firewall.apply')['payload']['rules'])->firstWhere('id', $rule->id))->toMatchArray(['action' => 'drop', 'ports' => ['8080']])
        ->and($this->agents->dispatched('security.fix'))->toHaveCount(0);

    // The next audit still sees the listener, but the deny rule closes it.
    $this->agents->succeed($this->agents->last('security.audit')['handle'], ['checks' => [security_check('firewall.port.tcp.8080', 'warn', 'low', 'firewall.close_port:tcp:8080', 'firewall')], 'duration_ms' => 1]);
    $finding = Audit::query()->latest('id')->first()->findings()->where('check_id', 'firewall.port.tcp.8080')->sole();
    expect($finding->status)->toBe('pass')->and($finding->fix_id)->toBeNull()->and($finding->evidence)->toContain('closed by a firewall deny rule');

    $this->post("/security/servers/{$this->server->id}/fixes/{$run->id}/undo")->assertSessionHasNoErrors();
    expect(FirewallRule::query()->whereKey($rule->id)->exists())->toBeFalse()
        ->and($run->refresh()->status)->toBe(FixStatus::Undone)
        ->and(AuditEntry::query()->where('action', 'network.firewall_rule_deleted')->count())->toBe(1);
});

it('re-applies the firewall when its table is missing', function () {
    security_audit($this->agents, $this->server, [security_check('firewall.default_deny', 'fail', 'high', 'firewall.apply', 'firewall')]);
    $before = count($this->agents->dispatched('net.firewall.apply'));

    $this->post("/security/servers/{$this->server->id}/fixes", ['fix_id' => 'firewall.apply'])->assertSessionHasNoErrors();

    expect($this->agents->dispatched('net.firewall.apply'))->toHaveCount($before + 1)
        ->and(FixRun::query()->sole()->only('status', 'undoable'))->toBe(['status' => FixStatus::Applied, 'undoable' => false]);
});

it('alerts when the score drops, on new critical findings and new public ports', function () {
    Event::fake([SecurityScoreDropped::class, CriticalFindingDetected::class, UnexpectedPortDetected::class]);

    security_audit($this->agents, $this->server, [security_check('ssh.password_authentication'), security_check('docker.tcp')]);
    Event::assertNotDispatched(SecurityScoreDropped::class);
    Event::assertNotDispatched(CriticalFindingDetected::class);

    security_audit($this->agents, $this->server, [
        security_check('ssh.password_authentication', 'fail', 'high', 'ssh.harden'),
        security_check('docker.tcp', 'warn', 'medium', area: 'docker'),
        security_check('firewall.docker_published', 'fail', 'high', area: 'firewall'),
    ]);
    Event::assertDispatched(SecurityScoreDropped::class, fn ($e) => $e->from === 100 && $e->to === 68);
    Event::assertDispatchedTimes(CriticalFindingDetected::class, 1);
    Event::assertDispatched(CriticalFindingDetected::class, fn ($e) => $e->checkId === 'ssh.password_authentication' && $e->toAlert()->url === "/servers/{$this->server->id}/security");
    Event::assertDispatchedTimes(UnexpectedPortDetected::class, 1);

    // Still failing: no new alerts.
    security_audit($this->agents, $this->server, [security_check('ssh.password_authentication', 'fail', 'high', 'ssh.harden'), security_check('firewall.docker_published', 'fail', 'high', area: 'firewall')]);
    Event::assertDispatchedTimes(CriticalFindingDetected::class, 1);
    Event::assertDispatchedTimes(UnexpectedPortDetected::class, 1);
    Event::assertDispatchedTimes(SecurityScoreDropped::class, 1);
});

it('keeps organizations apart', function () {
    security_audit($this->agents, $this->server, [security_check('a', 'fail', 'low', 'kernel.sysctl', 'kernel')]);
    [, $other] = actingAsMember(Role::Admin);
    $theirs = security_server($other, ['name' => 'theirs']);

    $this->get("/servers/{$this->server->id}/security")->assertNotFound();
    $this->post("/security/servers/{$this->server->id}/fixes", ['fix_id' => 'kernel.sysctl'])->assertNotFound();
    $this->post("/security/servers/{$this->server->id}/audit")->assertNotFound();
    $this->get('/security')->assertOk()->assertInertia(fn ($page) => $page->has('servers', 1)->where('servers.0.name', 'theirs'));

    // Command outcomes only settle rows of the organization that sent them.
    $run = FixRun::query()->create(['organization_id' => $this->organization->id, 'server_id' => $this->server->id, 'fix_id' => 'kernel.sysctl', 'status' => FixStatus::Applying, 'command_id' => '01J9Z8Y7X6W5V4T3S2R1Q0P9NA']);
    CommandFinished::dispatch('01J9Z8Y7X6W5V4T3S2R1Q0P9NA', $other->id, $theirs->id, 'security.fix', 'k', 0, ['changed' => true]);
    expect($run->refresh()->status)->toBe(FixStatus::Applying);
});

it('lists every server with its score and badge', function () {
    security_audit($this->agents, $this->server, [security_check('a', 'fail', 'critical', area: 'accounts')]);
    security_server($this->organization, ['name' => 'db-1']);

    $this->get('/security')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Security/Index', false)
        ->has('servers', 2)
        ->where('servers.1.audit.score', 70)
        ->where('servers.1.audit.production_ready', false)
        ->where('servers.0.audit', null));
});
