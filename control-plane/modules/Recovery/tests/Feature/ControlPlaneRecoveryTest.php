<?php

use Falak\Alerting\Contracts\Severity;
use Falak\Identity\Contracts\Role;
use Falak\Recovery\Application\ControlPlaneStatus;
use Falak\Recovery\Application\Jobs\CheckControlPlaneRecovery;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    Carbon::setTestNow('2026-10-10 12:00:00');
    [$this->owner, $this->organization] = actingAsMember(Role::Owner);
});

afterEach(function () {
    @unlink((string) config('recovery.status_path'));
    Carbon::setTestNow();
});

function recovery_banner(object $test): mixed
{
    $props = null;
    $test->get('/settings/profile')->assertOk()->assertInertia(function (Assert $page) use (&$props) {
        $props = $page->toArray()['props']['disasterRecovery'] ?? null;
    });

    return $props;
}

it('reads the status file falak-ctl writes', function () {
    recovery_status_file();
    $status = new ControlPlaneStatus;

    expect($status->available())->toBeTrue()
        ->and($status->configured())->toBeTrue()
        ->and($status->needsSetup())->toBeFalse()
        ->and($status->scheduleHours())->toBe(6)
        ->and($status->lastBackupAt()?->toIso8601String())->toBe(now()->subHour()->toIso8601String())
        ->and($status->backupFailing())->toBeFalse()
        ->and($status->backupMissing())->toBeFalse()
        ->and($status->drillFailed())->toBeFalse()
        ->and($status->toArray()['last_backup'])->toMatchArray(['age_seconds' => 3600, 'size_bytes' => 1234, 'uploaded' => true]);

    recovery_status_file(['last_backup' => ['at' => now()->subHours(13)->toIso8601String()], 'last_failure' => ['at' => now()->subMinutes(5)->toIso8601String(), 'error' => 'error: upload failed'], 'last_drill' => ['ok' => false]]);
    $status = new ControlPlaneStatus;
    expect($status->backupMissing())->toBeTrue()->and($status->backupFailing())->toBeTrue()->and($status->drillFailed())->toBeTrue()
        ->and($status->lastFailureError())->toBe('error: upload failed');

    file_put_contents((string) config('recovery.status_path'), '{not json');
    expect((new ControlPlaneStatus)->available())->toBeFalse();

    recovery_status_file(null);
    $status = new ControlPlaneStatus;
    expect($status->available())->toBeFalse()->and($status->configured())->toBeFalse()
        ->and($status->needsSetup())->toBeFalse(); // not production: development checkouts are not nagged

    app()->detectEnvironment(fn () => 'production');
    expect((new ControlPlaneStatus)->needsSetup())->toBeTrue();
});

it('shows the setup banner to the operator organization\'s owners and admins until DR is configured', function () {
    recovery_status_file(['configured' => false]);

    expect(recovery_banner($this))->toMatchArray(['banner' => true, 'configured' => false, 'needs_setup' => true, 'settings_url' => '/settings/disaster-recovery']);

    [$admin] = memberOf($this->organization, Role::Admin);
    $this->actingAs($admin);
    expect(recovery_banner($this))->toMatchArray(['banner' => true]);

    [$developer] = memberOf($this->organization, Role::Developer);
    $this->actingAs($developer);
    expect(recovery_banner($this))->toBeNull();

    recovery_status_file();
    $this->actingAs($this->owner);
    expect(recovery_banner($this))->toMatchArray(['banner' => false, 'configured' => true]);
});

it('never shows the control plane to other organizations of the install', function () {
    recovery_status_file(['configured' => false]);
    [$otherOwner] = actingAsMember(Role::Owner);

    expect(recovery_banner($this))->toBeNull();
    $this->get('/settings/disaster-recovery')->assertNotFound();
    $this->post('/settings/disaster-recovery/dismiss')->assertNotFound();

    // Unless the install names that organization as its operator.
    config(['recovery.operator_organization' => $otherOwner->current_organization_id]);
    expect(recovery_banner($this))->toMatchArray(['banner' => true]);
    $this->actingAs($this->owner);
    expect(recovery_banner($this))->toBeNull();

    // Several organizations and none recorded (install.sh writes FALAK_DR_ORGANIZATION): nobody, not "the oldest".
    config(['recovery.operator_organization' => '']);
    expect(recovery_banner($this))->toBeNull();
    $this->get('/settings/disaster-recovery')->assertNotFound();
    $alerts = recovery_alerts();
    dispatch_sync(new CheckControlPlaneRecovery);
    expect($alerts->raised)->toBe([]);
});

it('hides the banner for 30 days after a dismissal, then shows it again', function () {
    recovery_status_file(['configured' => false]);

    $this->post('/settings/disaster-recovery/dismiss')->assertRedirect();
    expect(recovery_banner($this))->toMatchArray(['banner' => false, 'needs_setup' => true]);

    Carbon::setTestNow(now()->addDays(29));
    expect(recovery_banner($this))->toMatchArray(['banner' => false]);

    Carbon::setTestNow(now()->addDays(2));
    expect(recovery_banner($this))->toMatchArray(['banner' => true]);

    // Another admin's dismissal is theirs alone.
    $this->post('/settings/disaster-recovery/dismiss');
    [$admin] = memberOf($this->organization, Role::Admin);
    $this->actingAs($admin);
    expect(recovery_banner($this))->toMatchArray(['banner' => true]);
});

it('renders Settings → Disaster recovery for admins with the commands and no secrets', function () {
    recovery_status_file(['last_failure' => ['at' => now()->toIso8601String(), 'error' => 'error: upload failed']]);

    $this->get('/settings/disaster-recovery')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Recovery/Settings', false)
        ->where('status.configured', true)
        ->where('status.target', 's3://dr-bucket/falak')
        ->where('status.backup_failing', true)
        ->where('status.last_drill.ok', true)
        ->where('commands.setup', 'sudo falak-ctl dr setup')
        ->has('commands.restore'));

    $json = json_encode($this->get('/settings/disaster-recovery')->viewData('page'));
    expect($json)->not->toContain('PASSPHRASE')->not->toContain('SECRET');

    [$developer] = memberOf($this->organization, Role::Developer);
    $this->actingAs($developer)->get('/settings/disaster-recovery')->assertForbidden();
    $this->post('/settings/disaster-recovery/dismiss')->assertForbidden();
});

it('reminds weekly while DR is not configured', function () {
    recovery_status_file(['configured' => false]);
    $alerts = recovery_alerts();

    dispatch_sync(new CheckControlPlaneRecovery);
    dispatch_sync(new CheckControlPlaneRecovery);
    Carbon::setTestNow(now()->addDays(7));
    dispatch_sync(new CheckControlPlaneRecovery);

    $reminders = $alerts->of(CheckControlPlaneRecovery::NOT_CONFIGURED);
    expect($reminders)->toHaveCount(3)
        ->and($reminders[0]->organizationId)->toBe($this->organization->id)
        ->and($reminders[0]->severity)->toBe(Severity::Warning)
        ->and($reminders[0]->dedupKey)->toBe($reminders[1]->dedupKey)        // the same week: delivered once
        ->and($reminders[2]->dedupKey)->not->toBe($reminders[0]->dedupKey)  // a week later: again
        ->and($reminders[0]->url)->toEndWith('/settings/disaster-recovery')
        ->and($alerts->of(CheckControlPlaneRecovery::BACKUP_MISSING))->toBe([]);
});

it('alerts on failed, missing and undrillable backups, and clears the missing alert', function () {
    $alerts = recovery_alerts();

    recovery_status_file();
    dispatch_sync(new CheckControlPlaneRecovery);
    expect($alerts->of(CheckControlPlaneRecovery::BACKUP_FAILED))->toBe([])
        ->and($alerts->of(CheckControlPlaneRecovery::DRILL_FAILED))->toBe([])
        ->and($alerts->of(CheckControlPlaneRecovery::BACKUP_MISSING, resolves: false))->toBe([])
        ->and($alerts->of(CheckControlPlaneRecovery::BACKUP_MISSING, resolves: true))->toHaveCount(1)
        ->and($alerts->of(CheckControlPlaneRecovery::NOT_CONFIGURED))->toBe([]);

    recovery_status_file([
        'last_backup' => ['at' => now()->subHours(13)->toIso8601String()],
        'last_failure' => ['at' => now()->subMinutes(10)->toIso8601String(), 'error' => 'error: upload to s3://dr-bucket failed'],
        'last_drill' => ['at' => now()->subDay()->toIso8601String(), 'ok' => false, 'message' => 'encryption keys: falak:keys:check failed'],
    ]);
    $alerts->raised = [];
    dispatch_sync(new CheckControlPlaneRecovery);

    $failed = $alerts->of(CheckControlPlaneRecovery::BACKUP_FAILED);
    $missing = $alerts->of(CheckControlPlaneRecovery::BACKUP_MISSING, resolves: false);
    $drill = $alerts->of(CheckControlPlaneRecovery::DRILL_FAILED);
    expect($failed)->toHaveCount(1)->and($failed[0]->severity)->toBe(Severity::Critical)->and($failed[0]->body)->toContain('upload')
        ->and($failed[0]->dedupKey)->toStartWith('dr.backup_failed:')
        ->and($missing)->toHaveCount(1)->and($missing[0]->dedupKey)->toBe('dr.backup_missing')
        ->and($drill)->toHaveCount(1)->and($drill[0]->body)->toContain('encryption keys');

    // A failure older than the last good backup is history.
    recovery_status_file(['last_failure' => ['at' => now()->subDays(2)->toIso8601String(), 'error' => 'old']]);
    $alerts->raised = [];
    dispatch_sync(new CheckControlPlaneRecovery);
    expect($alerts->of(CheckControlPlaneRecovery::BACKUP_FAILED))->toBe([]);
});
