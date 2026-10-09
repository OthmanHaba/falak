<?php

namespace Falak\Security\Domain;

/**
 * The fixes the control plane offers, mirroring the agent's compiled-in allowlist (agent/internal/security/fixes.go)
 * plus the fixes it applies itself (the firewall belongs to the Network module). Whether a fix is disruptive comes
 * from here, never from what an agent reports.
 */
final class FixCatalogue
{
    /**
     * @var array<string, array{label: string, disruptive: bool, agent: bool, undoable: bool}>
     */
    public const FIXES = [
        'ssh.harden' => ['label' => 'Harden SSH: no root or password logins (50-falak.conf, checked with sshd -t)', 'disruptive' => true, 'agent' => true, 'undoable' => true],
        'updates.unattended' => ['label' => 'Turn on unattended security updates', 'disruptive' => false, 'agent' => true, 'undoable' => true],
        'updates.install' => ['label' => 'Install the pending security updates now (services may restart)', 'disruptive' => true, 'agent' => true, 'undoable' => false],
        'updates.reboot' => ['label' => 'Reboot in the maintenance window', 'disruptive' => true, 'agent' => true, 'undoable' => true],
        'fail2ban.sshd' => ['label' => 'Guard SSH with fail2ban', 'disruptive' => false, 'agent' => true, 'undoable' => true],
        'docker.tcp_off' => ['label' => 'Turn off the Docker TCP socket (restarts Docker; live-restore keeps containers)', 'disruptive' => true, 'agent' => true, 'undoable' => true],
        'kernel.sysctl' => ['label' => 'Harden kernel network settings (90-falak-hardening.conf)', 'disruptive' => false, 'agent' => true, 'undoable' => true],
        'files.secret_permissions' => ['label' => 'Make secret files private', 'disruptive' => false, 'agent' => true, 'undoable' => true],
        'time.sync' => ['label' => 'Keep the clock in sync (systemd-timesyncd)', 'disruptive' => false, 'agent' => true, 'undoable' => true],
        'firewall.apply' => ['label' => 'Apply the firewall again', 'disruptive' => false, 'agent' => false, 'undoable' => false],
        'firewall.close_port' => ['label' => 'Close the port with a firewall deny rule', 'disruptive' => false, 'agent' => false, 'undoable' => true],
    ];

    /**
     * Look a fix up; control-plane fixes carry parameters ("firewall.close_port:tcp:8080").
     *
     * @return array{id: string, base: string, params: list<string>, label: string, disruptive: bool, agent: bool, undoable: bool}|null
     */
    public static function find(string $fixId): ?array
    {
        $parts = explode(':', $fixId);
        $base = array_shift($parts);
        $fix = self::FIXES[$base] ?? null;

        if ($fix === null || ($fix['agent'] && $parts !== [])) {
            return null;
        }

        if ($base === 'firewall.close_port' && self::port($parts) === null) {
            return null;
        }

        return ['id' => $fixId, 'base' => $base, 'params' => $parts, ...$fix];
    }

    /**
     * @param  list<string>  $params  ["tcp", "8080"]
     * @return array{0: string, 1: int}|null
     */
    public static function port(array $params): ?array
    {
        if (count($params) !== 2 || ! in_array($params[0], ['tcp', 'udp'], true) || ! ctype_digit($params[1])) {
            return null;
        }

        $port = (int) $params[1];

        return $port >= 1 && $port <= 65535 ? [$params[0], $port] : null;
    }
}
