<?php

use Falak\Servers\Domain\MachineCheck\Decision;
use Falak\Servers\Domain\MachineCheck\MachineReport;
use Falak\Servers\Domain\MachineCheck\Severity;
use Falak\Servers\Domain\Stack\Stack;

require_once __DIR__.'/../Support/machine_reports.php';

function mc_review_stack(): Stack
{
    return new Stack('frankenphp', ['8.4'], '8.4', '22');
}

it('adopts a Docker 28 or newer, and replaces an older one with Docker\'s docker-ce', function (string $version, Decision $decision) {
    $report = mc_docker_io(mc_report(), ['compose', 'buildx']);
    $report['packages'] = array_map(fn (array $p) => $p['name'] === 'docker.io' ? [...$p, 'version' => $version] : $p, $report['packages']);
    $report['docker']['server_version'] = MachineReport::upstream($version);
    $docker = mc_decide($report, mc_wanted(mc_review_stack()))->for('docker');

    expect($docker->decision)->toBe($decision);

    if ($decision === Decision::Install) {
        expect($docker->reason)->toContain('Replaces Docker '.MachineReport::upstream($version).' (older than 28)')
            ->and($docker->install)->toContain('docker-ce');
    }
})->with([
    'jammy release' => ['20.10.12-0ubuntu4', Decision::Install],
    'jammy-updates' => ['24.0.7-0ubuntu2~22.04.1', Decision::Install],
    'noble' => ['27.5.1-0ubuntu3~24.04.2', Decision::Install],
    'resolute' => ['28.2.2-0ubuntu1', Decision::Adopt],
]);

it('does not count root\'s keys when root may not log in', function (callable $change) {
    $report = mc_report();
    $report['ssh']['users'] = [['name' => 'root', 'uid' => 0, 'authorized_keys' => 2, 'groups' => ['root']], ['name' => 'ubuntu', 'uid' => 1000, 'authorized_keys' => 0, 'groups' => ['ubuntu', 'sudo']]];
    $ssh = mc_decide($change($report), mc_wanted(mc_review_stack()))->for('ssh');

    expect($ssh->decision)->toBe(Decision::Block)
        ->and($ssh->reason)->toContain('root has keys but is not allowed to log in');
})->with([
    'PermitRootLogin no' => [function (array $r) {
        $r['ssh']['effective']['permitrootlogin'] = 'no';

        return $r;
    }],
    'an earlier drop-in: forced-commands-only' => [function (array $r) {
        $r['ssh']['drop_ins'] = [['file' => '/etc/ssh/sshd_config.d/10-hardening.conf', 'settings' => ['permitrootlogin' => 'forced-commands-only']]];

        return $r;
    }],
    'DenyUsers root' => [function (array $r) {
        $r['ssh']['effective']['denyusers'] = 'root';

        return $r;
    }],
    'AllowUsers without root' => [function (array $r) {
        $r['ssh']['effective']['allowusers'] = 'deploy ubuntu@10.0.0.*';

        return $r;
    }],
    'AllowGroups sshusers' => [function (array $r) {
        $r['ssh']['effective']['allowgroups'] = 'sshusers';

        return $r;
    }],
]);

it('treats keyboard-interactive login as password login', function () {
    $report = mc_report();
    $report['ssh']['effective']['passwordauthentication'] = 'no';
    $report['ssh']['effective']['kbdinteractiveauthentication'] = 'yes';
    $report['ssh']['users'] = [['name' => 'ubuntu', 'uid' => 1000, 'authorized_keys' => 0, 'groups' => ['ubuntu']]];

    expect(mc_decide($report, mc_wanted(mc_review_stack()))->for('ssh')->decision)->toBe(Decision::Block);

    $report['ssh']['effective']['kbdinteractiveauthentication'] = 'no';
    expect(mc_decide($report, mc_wanted(mc_review_stack()))->for('ssh')->decision)->toBe(Decision::Install);
});

it('lets in users the allow lists match by name, wildcard or group', function () {
    $report = mc_report();
    $report['ssh']['users'] = [['name' => 'root', 'uid' => 0, 'authorized_keys' => 1, 'groups' => ['root']], ['name' => 'deploy', 'uid' => 1001, 'authorized_keys' => 1, 'groups' => ['deploy', 'sshusers']]];
    $report['ssh']['effective'] = [...$report['ssh']['effective'], 'permitrootlogin' => 'no', 'allowgroups' => 'ssh*'];

    expect(mc_decide($report, mc_wanted(mc_review_stack()))->for('ssh')->reason)->toBe('Key-only login, root without password, port 22. Keys found for deploy.');

    $report['ssh']['effective']['denygroups'] = 'sshusers';
    expect(mc_decide($report, mc_wanted(mc_review_stack()))->for('ssh')->decision)->toBe(Decision::Block);
});

it('writes Falak\'s automatic-update config over Ubuntu\'s stock one and keeps a customised one', function () {
    expect(mc_decide(mc_report(), mc_wanted(mc_review_stack()))->for('unattended_upgrades')->decision)->toBe(Decision::Install);

    $custom = mc_report(['unattended_upgrades' => ['installed' => true, 'periodic' => ['Update-Package-Lists' => '1', 'Unattended-Upgrade' => '1', 'AutocleanInterval' => '7'], 'managed_by_falak' => false]]);
    expect(mc_decide($custom, mc_wanted(mc_review_stack()))->for('unattended_upgrades')->decision)->toBe(Decision::Adopt);
});

it('warns about a Docker plugin the agent would not run', function () {
    $report = mc_docker_ce(mc_report());
    $report['docker']['compose'] = ['version' => '', 'path' => '/usr/local/lib/docker/cli-plugins/docker-compose'];
    $docker = mc_decide($report, mc_wanted(mc_review_stack()))->for('docker');

    expect($docker->decision)->toBe(Decision::Adopt)
        ->and($docker->severity())->toBe(Severity::Warning)
        ->and($docker->notes[0]->message)->toContain('was not run: a user other than root can change it');
});

it('names packages whose source apt cannot tell', function () {
    expect(MachineReport::sourceOf(['origin' => 'unknown']))->toBe('unknown source');
});
