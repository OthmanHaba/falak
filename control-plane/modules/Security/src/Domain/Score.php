<?php

namespace Falak\Security\Domain;

/**
 * A server's baseline score: 100 minus what its findings cost, never below 0.
 *
 * A failing check costs FAIL[severity] points, a warning WARN[severity] (about half); passing and info checks cost
 * nothing. One critical failure alone takes a server to 70, a high one to 85. The server is "production ready" when
 * no check of high or critical severity fails (warnings do not block it).
 */
final class Score
{
    /** @var array<string, int> */
    public const FAIL = ['critical' => 30, 'high' => 15, 'medium' => 5, 'low' => 2, 'info' => 0];

    /** @var array<string, int> */
    public const WARN = ['critical' => 15, 'high' => 7, 'medium' => 2, 'low' => 1, 'info' => 0];

    public const STATUSES = ['pass', 'warn', 'fail', 'info'];

    public const SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];

    /**
     * @param  iterable<array{status: string, severity: string}>  $checks
     * @return array{score: int, counts: array<string, int>, production_ready: bool}
     */
    public static function of(iterable $checks): array
    {
        $counts = array_fill_keys([...self::STATUSES, ...self::SEVERITIES], 0);
        $cost = 0;
        $ready = true;

        foreach ($checks as $check) {
            $status = $check['status'];
            $severity = $check['severity'];
            $counts[$status] = ($counts[$status] ?? 0) + 1;

            if ($status === 'fail') {
                $cost += self::FAIL[$severity] ?? 0;
                $ready = $ready && ! in_array($severity, ['critical', 'high'], true);
            } elseif ($status === 'warn') {
                $cost += self::WARN[$severity] ?? 0;
            }

            // Severity counts are of the findings that need attention.
            if ($status === 'fail' || $status === 'warn') {
                $counts[$severity] = ($counts[$severity] ?? 0) + 1;
            }
        }

        return ['score' => max(0, 100 - $cost), 'counts' => $counts, 'production_ready' => $ready];
    }
}
