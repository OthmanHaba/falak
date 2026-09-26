<?php

namespace Kiln\Processes\Infrastructure;

/**
 * Names of supervised programs and cron jobs: `<site slug>.<suffix>`, unique per server and valid for
 * proc.apply / cron.apply (^[a-z0-9][a-z0-9_.-]{0,62}$). Long slugs are shortened with a hash.
 */
final class ProgramNames
{
    private const MAX = 63;

    public static function horizon(string $slug): string
    {
        return self::name($slug, 'horizon');
    }

    /** The web process of a node / bun / deno site. */
    public static function app(string $slug): string
    {
        return self::name($slug, 'app');
    }

    public static function octane(string $slug): string
    {
        return self::name($slug, 'octane');
    }

    public static function scheduler(string $slug): string
    {
        return self::name($slug, 'schedule');
    }

    public static function worker(string $slug, string $workerId): string
    {
        return self::name($slug, 'worker-'.self::short($workerId));
    }

    public static function daemon(string $slug, string $daemonId): string
    {
        return self::name($slug, 'daemon-'.self::short($daemonId));
    }

    public static function cron(string $slug, string $scheduleId): string
    {
        return self::name($slug, 'cron-'.self::short($scheduleId));
    }

    public static function name(string $slug, string $suffix): string
    {
        $room = self::MAX - strlen($suffix) - 1;
        $base = strlen($slug) <= $room ? $slug : rtrim(substr($slug, 0, $room - 7), '-').'-'.substr(sha1($slug), 0, 6);

        return "{$base}.{$suffix}";
    }

    /** Random tail of a ULID (the first 10 characters are the timestamp). */
    private static function short(string $ulid): string
    {
        return strtolower(substr($ulid, -8));
    }
}
