<?php

use Falak\Servers\Domain\MachineCheck\ComponentDecision;
use Falak\Servers\Domain\MachineCheck\Decision;
use Falak\Servers\Domain\MachineCheck\MachineCheck;
use Falak\Servers\Domain\MachineCheck\MachineReport;
use Falak\Servers\Domain\MachineCheck\Severity;
use Falak\Servers\Domain\Stack\Stack;

require_once __DIR__.'/../Support/machine_reports.php';

/** App server stack: FrankenPHP 8.4, Node 22 (and Docker, which every server gets). */
function mc_app_stack(): Stack
{
    return new Stack('frankenphp', ['8.4'], '8.4', '22');
}

/**
 * @return array<string, string> component => decision
 */
function mc_decisions(MachineCheck $check): array
{
    return collect($check->components)->mapWithKeys(fn (ComponentDecision $d) => [$d->component => $d->decision->value])->all();
}

it('installs everything on a fresh machine', function () {
    $check = mc_decide(mc_report(), mc_wanted(mc_app_stack(), base: ['acl', 'curl', 'git']));

    expect(mc_decisions($check))->toBe([
        'base' => 'complete', 'docker' => 'install', 'edge' => 'install', 'php' => 'install', 'node' => 'install',
        'ssh' => 'install', 'firewall' => 'install', 'swap' => 'install', 'hostname' => 'adopt', 'unattended_upgrades' => 'install', 'fail2ban' => 'install',
    ])
        ->and($check->blocking())->toBeFalse()
        ->and($check->for('docker')->install)->toBe(['docker.io', 'docker-compose-v2', 'docker-buildx'])
        ->and($check->for('swap')->reason)->toBe('Creates a 2 GB /swapfile.')
        ->and($check->for('base')->install)->toBe(['acl'])
        // Ubuntu's stock 20auto-upgrades is no customisation: Falak writes its config as before.
        ->and($check->for('unattended_upgrades')->found[0]['source'])->toBe('default config')
        // ufw installed but inactive: nothing to warn about.
        ->and($check->for('firewall')->notes)->toBe([])
        ->and($check->for('ssh')->reason)->toBe('Key-only login, root without password, port 22. Keys found for root, ubuntu.')
        ->and(collect($check->components)->every(fn (ComponentDecision $d) => $d->severity() === Severity::Info))->toBeTrue();
});

it('adopts Docker from Docker\'s repository with its plugins (the incident machine)', function () {
    $check = mc_decide(mc_docker_ce(mc_report()), mc_wanted(mc_app_stack()));
    $docker = $check->for('docker');

    expect($docker->decision)->toBe(Decision::Adopt)
        ->and($docker->install)->toBe([])
        ->and($docker->keep)->toBe(['docker-ce', 'docker-compose-plugin', 'docker-buildx-plugin'])
        ->and($docker->service)->toBe('docker')
        ->and($docker->reason)->toBe('Uses Docker 28.1.1 from download.docker.com with compose and buildx; installs no Docker packages.')
        ->and($docker->found[0])->toBe(['name' => 'docker-ce', 'version' => '28.1.1', 'source' => 'download.docker.com']);
});

it('completes Docker from Docker\'s repository with docker-compose-plugin, never Ubuntu\'s packages', function () {
    $docker = mc_decide(mc_docker_ce(mc_report(), ['buildx']), mc_wanted(mc_app_stack()))->for('docker');

    expect($docker->decision)->toBe(Decision::Complete)
        ->and($docker->install)->toBe(['docker-compose-plugin'])
        ->and($docker->keep)->toBe(['docker-ce', 'docker-buildx-plugin'])
        ->and($docker->reason)->toContain("installs docker-compose-plugin from Docker's repository");

    $both = mc_decide(mc_docker_ce(mc_report(), []), mc_wanted(mc_app_stack()))->for('docker');
    expect($both->install)->toBe(['docker-compose-plugin', 'docker-buildx-plugin']);
});

it('blocks completing Docker from Docker\'s repository when that repository is not configured', function () {
    $docker = mc_decide(mc_docker_ce(mc_report(), ['buildx'], repoConfigured: false), mc_wanted(mc_app_stack()))->for('docker');

    expect($docker->decision)->toBe(Decision::Block)
        ->and($docker->reason)->toBe("Docker from Docker's repository has no compose, and that repository is not configured on the machine.")
        ->and($docker->hint())->toContain('docs.docker.com/engine/install/ubuntu')
        ->and($docker->install)->toBe([]);
});

it('completes Ubuntu\'s docker.io with docker-compose-v2', function () {
    $docker = mc_decide(mc_docker_io(mc_report(), ['buildx']), mc_wanted(mc_app_stack()))->for('docker');

    expect($docker->decision)->toBe(Decision::Complete)
        ->and($docker->install)->toBe(['docker-compose-v2'])
        ->and($docker->keep)->toBe(['docker.io', 'docker-buildx'])
        ->and($docker->reason)->toBe("Uses Docker 27.5.1 from Ubuntu archive; installs docker-compose-v2 from Ubuntu's archive.");

    expect(mc_decide(mc_docker_io(mc_report(), ['compose', 'buildx']), mc_wanted(mc_app_stack()))->for('docker')->decision)->toBe(Decision::Adopt);
});

it('blocks snap, rootless-only, podman and too old Docker', function (callable $machine, string $reason) {
    $docker = mc_decide($machine(), mc_wanted(mc_app_stack()))->for('docker');

    expect($docker->decision)->toBe(Decision::Block)
        ->and($docker->reason)->toContain($reason)
        ->and($docker->hint())->not->toBeNull();
})->with([
    'snap' => [fn () => mc_report(['snaps' => [['name' => 'docker', 'version' => '27.2.0']], 'docker' => ['snap' => true, 'system_daemon' => false, 'rootless' => false, 'compose' => null, 'buildx' => null]]), 'Docker is installed as a snap'],
    'rootless only' => [fn () => mc_report(['docker' => ['snap' => false, 'system_daemon' => false, 'rootless' => true, 'compose' => null, 'buildx' => null]]), 'Only a rootless Docker'],
    'podman-docker' => [fn () => mc_package(mc_report(['docker' => ['engine_package' => 'podman-docker', 'system_daemon' => false]]), 'podman-docker', '4.9.3'), 'podman-docker provides'],
    'too old' => [fn () => array_replace_recursive(mc_docker_io(mc_report(), ['compose', 'buildx']), ['docker' => ['server_version' => '19.03.13']]), 'Docker 19.03.13 is older than 20.10'],
    'masked docker.service' => [fn () => array_replace(mc_docker_io(mc_report(), ['compose', 'buildx']), ['services' => [['unit' => 'docker.service', 'active' => 'inactive', 'enabled' => 'masked']]]), 'docker.service is masked'],
    'CLI only' => [fn () => mc_package(mc_report(['docker' => ['engine_package' => '', 'client_version' => '28.1.1', 'snap' => false, 'rootless' => false, 'system_daemon' => false, 'compose' => null, 'buildx' => null]]), 'docker-ce-cli', '5:28.1.1-1~ubuntu.24.04~noble', 'vendor', 'https://download.docker.com/linux/ubuntu'), 'Only the Docker CLI is installed'],
]);

it('warns about daemon.json settings that break published ports', function () {
    $report = mc_docker_ce(mc_report());
    $report['docker']['daemon'] = ['iptables' => false, 'userns_remap' => 'default', 'default_address_pools' => [['base' => '10.200.0.0/16', 'size' => 24]]];
    $docker = mc_decide($report, mc_wanted(mc_app_stack()))->for('docker');

    expect($docker->decision)->toBe(Decision::Adopt)
        ->and($docker->severity())->toBe(Severity::Warning)
        ->and(array_map(fn ($n) => $n->severity->value, $docker->notes))->toBe(['warning', 'warning', 'info']);
});

it('blocks nginx on port 80', function () {
    $report = mc_listen(mc_service(mc_package(mc_report(), 'nginx', '1.24.0-2ubuntu7'), 'nginx.service'), 80, 'nginx', 'nginx.service');
    $check = mc_decide($report, mc_wanted(mc_app_stack()));
    $edge = $check->for('edge');

    expect($edge->decision)->toBe(Decision::Block)
        ->and($edge->reason)->toBe("Port 80 is in use by nginx, which Falak's edge needs.")
        ->and($edge->hint())->toBe('Stop and disable it (systemctl disable --now nginx.service) or move it to another port, then re-check.')
        ->and($check->blocking())->toBeTrue()
        ->and($check->components[0]->component)->toBe('edge')
        ->and($check->summary())->toBe("Machine check: 1 conflict to fix before provisioning. Port 80 is in use by nginx, which Falak's edge needs.");
});

it('accepts Falak\'s own edge on its ports (re-provisioning)', function () {
    $report = mc_service(mc_listen(mc_listen(mc_report(), 80, 'frankenphp', 'falak-edge.service'), 443, 'frankenphp', 'falak-edge.service'), 'falak-edge.service');

    expect(mc_decide($report, mc_wanted(mc_app_stack()))->for('edge')->decision)->toBe(Decision::Adopt);
});

it('blocks an active caddy.service and warns about an enabled but stopped Apache', function () {
    $report = mc_service(mc_service(mc_report(), 'caddy.service'), 'apache2.service', 'inactive', 'enabled');
    $edge = mc_decide($report, mc_wanted(mc_app_stack()))->for('edge');

    expect($edge->decision)->toBe(Decision::Block)
        ->and($edge->reason)->toContain('caddy.service is running')
        ->and(collect($edge->notes)->pluck('severity')->map->value->all())->toBe(['block', 'warning']);
});

it('installs no database engine and leaves one already on the machine alone', function () {
    $report = mc_listen(mc_package(mc_report(), 'postgresql-17', '17.2-1.pgdg24.04+1', 'vendor', 'https://apt.postgresql.org/pub/repos/apt'), 5432, 'postgres', 'postgresql@17-main.service');
    $check = mc_decide($report, mc_wanted(mc_app_stack()));

    expect($check->for('database'))->toBeNull()
        ->and($check->for('cache'))->toBeNull()
        ->and($check->blocking())->toBeFalse();
});

it('still blocks a container publishing a port the edge needs', function () {
    $report = mc_report();
    $report['containers'] = [['name' => 'proxy', 'image' => 'nginx:1', 'ports' => [['host_ip' => '0.0.0.0', 'host_port' => 443, 'container_port' => 443, 'protocol' => 'tcp']]]];
    $edge = mc_decide($report, mc_wanted(mc_app_stack()))->for('edge');

    expect($edge->decision)->toBe(Decision::Block)
        ->and($edge->reason)->toBe("A container (proxy, nginx:1) publishes port 443, which Falak's edge needs.");
});

it('blocks SSH hardening that would lock everyone out', function () {
    $report = mc_report();
    $report['ssh']['users'] = [['name' => 'root', 'uid' => 0, 'authorized_keys' => 0], ['name' => 'ubuntu', 'uid' => 1000, 'authorized_keys' => 0]];
    $ssh = mc_decide($report, mc_wanted(mc_app_stack()))->for('ssh');

    expect($ssh->decision)->toBe(Decision::Block)
        ->and($ssh->reason)->toBe('Password login would be turned off, but no user who may log in over SSH has a key in authorized_keys.');

    // Password login already off: nothing changes for anyone, no lockout.
    $report['ssh']['effective']['passwordauthentication'] = 'no';
    expect(mc_decide($report, mc_wanted(mc_app_stack()))->for('ssh')->decision)->toBe(Decision::Install);

    // A key for any login user is enough.
    $report['ssh']['effective']['passwordauthentication'] = 'yes';
    $report['ssh']['users'][1]['authorized_keys'] = 2;
    expect(mc_decide($report, mc_wanted(mc_app_stack()))->for('ssh')->reason)->toBe('Key-only login, root without password, port 22. Keys found for ubuntu.');
});

it('warns about sshd drop-ins that win over Falak\'s and a moved SSH port', function () {
    $report = mc_report();
    $report['ssh']['drop_ins'] = [
        ['file' => '/etc/ssh/sshd_config.d/50-cloud-init.conf', 'settings' => ['passwordauthentication' => 'yes']],
        ['file' => '/etc/ssh/sshd_config.d/60-custom.conf', 'settings' => ['passwordauthentication' => 'no']],
    ];
    $report['ssh']['effective']['port'] = '2222';
    $ssh = mc_decide($report, mc_wanted(mc_app_stack()))->for('ssh');

    expect($ssh->decision)->toBe(Decision::Install)
        ->and($ssh->severity())->toBe(Severity::Warning)
        ->and(collect($ssh->notes)->pluck('message')->all())->toBe([
            "/etc/ssh/sshd_config.d/50-cloud-init.conf sets PasswordAuthentication yes and is read before Falak's 50-falak.conf, so it wins.",
            'sshd listens on port 2222 today; Falak moves SSH to port 22.',
        ]);
});

it('warns, but does not block, when ufw is active', function () {
    $report = mc_report(['firewall' => ['ufw' => 'active', 'firewalld' => 'absent', 'nft_tables' => ['ip filter', 'ip nat', 'inet falak', 'inet custom']]]);
    $check = mc_decide($report, mc_wanted(mc_app_stack()));
    $firewall = $check->for('firewall');

    expect($firewall->decision)->toBe(Decision::Install)
        ->and($firewall->severity())->toBe(Severity::Warning)
        ->and($firewall->hint())->toBe('Allow the ports Falak opens (ufw allow 80,443/tcp) or turn ufw off (ufw disable).')
        ->and($firewall->notes[1]->message)->toBe('Other nftables tables stay as they are: inet custom.')
        ->and($check->blocking())->toBeFalse();
});

it('adopts existing swap instead of creating /swapfile', function () {
    $check = mc_decide(mc_report(['swap' => [['name' => '/swap.img', 'type' => 'file', 'size_bytes' => 4 * 1024 ** 3]]]), mc_wanted(mc_app_stack()));

    expect($check->for('swap')->decision)->toBe(Decision::Adopt)
        ->and($check->for('swap')->reason)->toBe('Keeps the existing swap (/swap.img); no /swapfile is created.');

    // Falak's own /swapfile converges as before; enough memory needs none.
    expect(mc_decide(mc_report(['swap' => [['name' => '/swapfile', 'type' => 'file', 'size_bytes' => 1 << 30]]]), mc_wanted(mc_app_stack()))->for('swap')->decision)->toBe(Decision::Install)
        ->and(mc_decide(mc_report(), mc_wanted(mc_app_stack(), swapMb: 0))->for('swap')->decision)->toBe(Decision::Skip);
});

it('keeps a custom server\'s hostname and names provider servers', function () {
    expect(mc_decide(mc_report(), mc_wanted(mc_app_stack()))->for('hostname')->reason)->toBe("Keeps the machine's hostname ubuntu-s-1vcpu.")
        ->and(mc_decide(mc_report(), mc_wanted(mc_app_stack(), custom: false))->for('hostname')->decision)->toBe(Decision::Install);
});

it('adopts an existing unattended-upgrades config and fail2ban with custom jails', function () {
    $report = mc_package(mc_report([
        'unattended_upgrades' => ['installed' => true, 'periodic' => ['Update-Package-Lists' => '1', 'Unattended-Upgrade' => '0'], 'managed_by_falak' => false],
        'fail2ban' => ['installed' => true, 'active' => true, 'jails' => ['/etc/fail2ban/jail.local', '/etc/fail2ban/jail.d/nginx.conf']],
    ]), 'fail2ban', '1.0.2-3ubuntu0.1');
    $check = mc_decide($report, mc_wanted(mc_app_stack()));

    expect($check->for('unattended_upgrades')->decision)->toBe(Decision::Adopt)
        ->and($check->for('unattended_upgrades')->severity())->toBe(Severity::Warning)
        ->and($check->for('fail2ban')->decision)->toBe(Decision::Adopt)
        ->and($check->for('fail2ban')->keep)->toBe(['fail2ban'])
        ->and($check->for('fail2ban')->notes[0]->message)->toBe('2 custom jail files stay; Falak writes no jails.');

    $falak = mc_report(['unattended_upgrades' => ['installed' => true, 'periodic' => ['Unattended-Upgrade' => '1'], 'managed_by_falak' => true]]);
    expect(mc_decide($falak, mc_wanted(mc_app_stack()))->for('unattended_upgrades')->decision)->toBe(Decision::Install);
});

it('keeps Falak\'s Node path and reports other Node installs as info', function () {
    $report = mc_report(['node' => [
        ['path' => '/usr/bin/node', 'version' => '20.18.1', 'source' => 'nodesource', 'package' => 'nodejs', 'repo' => 'https://deb.nodesource.com/node_20.x'],
        ['path' => '/root/.nvm/versions/node/v18.20.4/bin/node', 'version' => '18.20.4', 'source' => 'nvm'],
    ]]);
    $node = mc_decide($report, mc_wanted(mc_app_stack()))->for('node');

    expect($node->decision)->toBe(Decision::Install)
        ->and($node->severity())->toBe(Severity::Info)
        ->and($node->notes[0]->message)->toBe("Node 20.18.1 at /usr/bin/node (NodeSource) stays as it is; sites run Falak's Node.");

    $falak = mc_report(['node' => [['path' => '/usr/local/bin/node', 'version' => config('servers.node_versions.22'), 'source' => 'falak']]]);
    expect(mc_decide($falak, mc_wanted(mc_app_stack()))->for('node')->decision)->toBe(Decision::Adopt);
});

it('completes PHP that is partly there and warns about a PHP binary shadowing Falak\'s', function () {
    $report = mc_report(['php' => [
        ['path' => '/usr/bin/php8.4', 'version' => '8.4', 'source' => 'vendor', 'package' => 'php8.4-cli', 'repo' => 'https://ppa.launchpadcontent.net/ondrej/php/ubuntu'],
        ['path' => '/usr/local/bin/php', 'version' => '8.2', 'source' => 'manual'],
    ]]);
    $php = mc_decide($report, mc_wanted(mc_app_stack()))->for('php');

    expect($php->decision)->toBe(Decision::Complete)
        ->and($php->reason)->toBe('PHP 8.4 is installed (ppa.launchpadcontent.net); adds the missing versions and extensions from the same source.')
        ->and($php->severity())->toBe(Severity::Warning);
});

it('round-trips decisions through their stored form', function () {
    $check = mc_decide(mc_listen(mc_docker_ce(mc_report(), ['buildx']), 80, 'nginx', 'nginx.service'), mc_wanted(mc_app_stack()));

    expect(MachineCheck::fromArray(json_decode(json_encode($check->toArray()), true))->toArray())->toBe($check->toArray());
});

it('reads Debian versions and sources', function () {
    expect(MachineReport::upstream('5:28.1.1-1~ubuntu.24.04~noble'))->toBe('28.1.1')
        ->and(MachineReport::upstream('1:10.11.8-0ubuntu0.24.04.1'))->toBe('10.11.8')
        ->and(MachineReport::upstream('2.33.1+ds1-0ubuntu1'))->toBe('2.33.1')
        ->and(MachineReport::upstream(''))->toBeNull()
        ->and(MachineReport::sourceOf(['origin' => 'archive', 'label' => 'Ubuntu']))->toBe('Ubuntu archive')
        ->and(MachineReport::sourceOf(['origin' => 'vendor', 'repo' => 'https://download.docker.com/linux/ubuntu']))->toBe('download.docker.com')
        ->and(MachineReport::sourceOf(['origin' => 'manual']))->toBe('manual install');
});
