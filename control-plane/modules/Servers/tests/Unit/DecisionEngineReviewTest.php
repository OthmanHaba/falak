<?php

use Kiln\Servers\Domain\MachineCheck\Decision;
use Kiln\Servers\Domain\MachineCheck\MachineReport;
use Kiln\Servers\Domain\MachineCheck\Severity;
use Kiln\Servers\Domain\Stack\Stack;

require_once __DIR__.'/../Support/machine_reports.php';

function mc_review_stack(?string $database = 'postgresql', ?string $cache = 'redis'): Stack
{
    return new Stack('frankenphp', ['8.4'], '8.4', '22', $database, $cache, true);
}

/*
 * What Kiln itself installs on each supported Ubuntu release (the archive's versions): a server Kiln provisioned must
 * never block on its own engines. Valkey first ships with 26.04.
 */
dataset('kiln_installed_engines', [
    'jammy postgresql' => ['22.04', 'database', 'postgresql', 'postgresql-14', '14.18-0ubuntu0.22.04.1', '14'],
    'jammy mysql' => ['22.04', 'database', 'mysql', 'mysql-server-8.0', '8.0.42-0ubuntu0.22.04.1', '8.0.42'],
    'jammy mariadb' => ['22.04', 'database', 'mariadb', 'mariadb-server', '1:10.6.22-0ubuntu0.22.04.1', '10.6.22'],
    'jammy redis' => ['22.04', 'cache', 'redis', 'redis-server', '5:6.0.16-1ubuntu1.1', '6.0.16'],
    'noble postgresql' => ['24.04', 'database', 'postgresql', 'postgresql-16', '16.9-0ubuntu0.24.04.1', '16'],
    'noble mysql' => ['24.04', 'database', 'mysql', 'mysql-server-8.0', '8.0.42-0ubuntu0.24.04.1', '8.0.42'],
    'noble mariadb' => ['24.04', 'database', 'mariadb', 'mariadb-server', '1:10.11.13-0ubuntu0.24.04.1', '10.11.13'],
    'noble redis' => ['24.04', 'cache', 'redis', 'redis-server', '5:7.0.15-1ubuntu0.24.04.1', '7.0.15'],
    'resolute postgresql' => ['26.04', 'database', 'postgresql', 'postgresql-18', '18.0-1', '18'],
    'resolute mysql' => ['26.04', 'database', 'mysql', 'mysql-server-8.4', '8.4.6-0ubuntu1', '8.4.6'],
    'resolute mariadb' => ['26.04', 'database', 'mariadb', 'mariadb-server', '1:11.8.2-1', '11.8.2'],
    'resolute redis' => ['26.04', 'cache', 'redis', 'redis-server', '5:8.0.2-2', '8.0.2'],
    'resolute valkey' => ['26.04', 'cache', 'valkey', 'valkey-server', '8.1.1+dfsg1-2', '8.1.1'],
]);

it('adopts the engine Kiln installs on every supported release', function (string $os, string $component, string $engine, string $package, string $version, string $shown) {
    $report = mc_package(mc_report(['os' => ['id' => 'ubuntu', 'version' => $os]]), $package, $version);
    $stack = $component === 'database' ? mc_review_stack($engine) : mc_review_stack(cache: $engine);
    $decision = mc_decide($report, mc_wanted($stack))->for($component);

    expect($decision->decision)->toBe(Decision::Adopt)
        ->and($decision->found[0]['version'])->toBe($shown);
})->with('kiln_installed_engines');

it('adopts the docker.io Kiln installs on every supported release', function (string $version) {
    $report = mc_docker_io(mc_report(), ['compose', 'buildx']);
    $report['packages'] = array_map(fn (array $p) => $p['name'] === 'docker.io' ? [...$p, 'version' => $version] : $p, $report['packages']);
    $report['docker']['server_version'] = MachineReport::upstream($version);

    expect(mc_decide($report, mc_wanted(mc_review_stack()))->for('docker')->decision)->toBe(Decision::Adopt);
})->with([
    'jammy release' => '20.10.12-0ubuntu4',
    'jammy-updates' => '24.0.7-0ubuntu2~22.04.1',
    'noble' => '27.5.1-0ubuntu3~24.04.2',
    'resolute' => '28.2.2-0ubuntu1',
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

it('writes Kiln\'s automatic-update config over Ubuntu\'s stock one and keeps a customised one', function () {
    expect(mc_decide(mc_report(), mc_wanted(mc_review_stack()))->for('unattended_upgrades')->decision)->toBe(Decision::Install);

    $custom = mc_report(['unattended_upgrades' => ['installed' => true, 'periodic' => ['Update-Package-Lists' => '1', 'Unattended-Upgrade' => '1', 'AutocleanInterval' => '7'], 'managed_by_kiln' => false]]);
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
