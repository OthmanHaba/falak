<?php

namespace Kiln\Templates\Infrastructure;

use Kiln\Templates\Application\Compose\ComposeAnalyzer;
use Kiln\Templates\Application\Compose\ComposeDocument;
use Kiln\Templates\Application\Compose\ComposeFacts;

/**
 * TEMPORARY stand-in for `Sites\Contracts\ComposeInspector` until the compose runtime (lane A) merges: the
 * provider binds it only while no ComposeInspector is bound. Delete this class (and that branch of the binding)
 * once lane A is in.
 *
 * Applies the default (strict) policy of docs/COMPOSE_TEMPLATES.md §1.3.
 */
final class FallbackComposeAnalyzer implements ComposeAnalyzer
{
    /** Docker's default capability set plus NET_BIND_SERVICE; anything else needs "Allow privileged compose". */
    private const SAFE_CAPS = ['CHOWN', 'DAC_OVERRIDE', 'FOWNER', 'FSETID', 'KILL', 'SETGID', 'SETUID', 'SETPCAP', 'NET_BIND_SERVICE', 'NET_RAW', 'SYS_CHROOT', 'MKNOD', 'AUDIT_WRITE', 'SETFCAP'];

    public function analyze(string $yaml): ComposeFacts
    {
        $compose = ComposeDocument::parse($yaml);
        $services = [];
        $violations = [];

        foreach ($compose->services() as $name => $service) {
            $services[$name] = $compose->containerPorts($name);
            $at = "services.{$name}";

            if (($service['privileged'] ?? false) === true) {
                $violations[] = "{$at}: privileged containers are not allowed";
            }

            foreach (['network_mode', 'pid', 'ipc', 'userns_mode'] as $namespace) {
                if (($service[$namespace] ?? null) === 'host') {
                    $violations[] = "{$at}: {$namespace}: host is not allowed";
                }
            }

            foreach ((array) ($service['cap_add'] ?? []) as $cap) {
                $cap = strtoupper(preg_replace('/^CAP_/i', '', (string) $cap) ?? '');

                if ($cap === 'ALL' || ! in_array($cap, self::SAFE_CAPS, true)) {
                    $violations[] = "{$at}: cap_add {$cap} is not allowed";
                }
            }

            if (! empty($service['devices'])) {
                $violations[] = "{$at}: devices are not allowed";
            }

            foreach ((array) ($service['volumes'] ?? []) as $volume) {
                $source = is_array($volume) ? (string) ($volume['source'] ?? '') : explode(':', (string) $volume)[0];
                $type = is_array($volume) ? (string) ($volume['type'] ?? 'volume') : null;

                if (str_contains($source, 'docker.sock')) {
                    $violations[] = "{$at}: mounting the Docker socket is not allowed";
                } elseif (self::isHostPath($source) && ! self::insideRelease($source)) {
                    $violations[] = "{$at}: bind mount {$source} is outside the release directory";
                } elseif ($type === 'bind' && ! self::insideRelease($source)) {
                    $violations[] = "{$at}: bind mount {$source} is outside the release directory";
                }
            }
        }

        $volumes = array_map('strval', array_keys((array) ($compose->data['volumes'] ?? [])));

        return new ComposeFacts($services, $volumes, $violations);
    }

    private static function isHostPath(string $source): bool
    {
        return $source !== '' && ($source[0] === '/' || $source[0] === '.' || $source[0] === '~' || str_contains($source, '/'));
    }

    private static function insideRelease(string $source): bool
    {
        return $source !== '' && ($source === '.' || str_starts_with($source, './')) && ! preg_match('#(^|/)\.\.(/|$)#', $source);
    }
}
