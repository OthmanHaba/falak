<?php

namespace Falak\Processes\Http\Requests;

use Closure;
use Cron\CronExpression;
use DateTimeZone;
use Illuminate\Validation\Rule;
use Falak\Processes\Domain\Models\Daemon;
use Falak\Sites\Contracts\Data\SiteData;

/**
 * Validation rules for workers, daemons and scheduled jobs of a site.
 */
final class ProcessRules
{
    public const PRESETS = ['@hourly', '@daily', '@weekly', '@monthly', '@yearly'];

    /**
     * @return array<string, mixed>
     */
    public static function worker(SiteData $site): array
    {
        return [
            'connection' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'queue' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_.:{}\-]+(\s*,\s*[A-Za-z0-9_.:{}\-]+)*$/'],
            'command' => ['nullable', 'string', 'max:1000', 'not_regex:/\x00/'],
            'processes' => ['required', 'integer', 'min:1', 'max:64'],
            'timeout' => ['required', 'integer', 'min:0', 'max:86400'],
            'sleep' => ['required', 'integer', 'min:0', 'max:3600'],
            'tries' => ['required', 'integer', 'min:0', 'max:1000'],
            'backoff' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'max_jobs' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'max_time' => ['nullable', 'integer', 'min:0', 'max:604800'],
            'memory' => ['required', 'integer', 'min:32', 'max:65536'],
            ...self::env(),
            ...self::servers($site),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function daemon(SiteData $site): array
    {
        return [
            'name' => ['required', 'string', 'max:64'],
            'command' => ['required', 'string', 'max:2000', 'not_regex:/\x00/'],
            'directory' => ['nullable', 'string', 'max:255', 'regex:/^\/[^\x00]*$/', 'not_regex:/(^|\/)\.\.(\/|$)/'],
            'user' => self::user(),
            'instances' => ['required', 'integer', 'min:1', 'max:64'],
            'restart' => ['required', Rule::in(Daemon::RESTART_POLICIES)],
            'stop_signal' => ['required', Rule::in(Daemon::STOP_SIGNALS)],
            'stop_timeout' => ['required', 'integer', 'min:1', 'max:3600'],
            ...self::env(),
            ...self::servers($site),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function schedule(): array
    {
        return [
            'name' => ['required', 'string', 'max:64'],
            'command' => ['required', 'string', 'max:2000', 'not_regex:/\x00/'],
            'expression' => ['required', 'string', 'max:64', function (string $attribute, mixed $value, Closure $fail) {
                if (! self::validExpression((string) $value)) {
                    $fail('Use a 5-field cron expression, @hourly, @daily, @weekly, @monthly, @yearly or "@every 5m".');
                }
            }],
            'timezone' => ['nullable', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'user' => self::user(),
            'overlap' => ['required', Rule::in(['allow', 'skip'])],
            'timeout' => ['required', 'integer', 'min:1', 'max:86400'],
            'heartbeat' => ['required', 'boolean'],
            'enabled' => ['required', 'boolean'],
            'all_servers' => ['required', 'boolean'],
        ];
    }

    public static function validExpression(string $expression): bool
    {
        $expression = preg_replace('/\s+/', ' ', trim($expression)) ?? '';

        if (in_array($expression, self::PRESETS, true)) {
            return true;
        }

        if (str_starts_with($expression, '@every ')) {
            // Go duration (what the agent's scheduler parses), at least one second.
            $duration = substr($expression, 7);

            if (preg_match('/^(\d+(ns|us|µs|ms|s|m|h))+$/', $duration) !== 1) {
                return false;
            }

            preg_match_all('/(\d+)(ns|us|µs|ms|s|m|h)/', $duration, $parts, PREG_SET_ORDER);
            $seconds = 0.0;

            foreach ($parts as [, $amount, $unit]) {
                $seconds += (int) $amount * ['ns' => 1e-9, 'us' => 1e-6, 'µs' => 1e-6, 'ms' => 1e-3, 's' => 1, 'm' => 60, 'h' => 3600][$unit];
            }

            return $seconds >= 1;
        }

        return count(explode(' ', $expression)) === 5 && CronExpression::isValidExpression($expression);
    }

    /**
     * @return list<mixed>
     */
    private static function user(): array
    {
        return ['nullable', 'string', 'regex:/^[a-z_][a-z0-9_-]{0,31}$/', Rule::notIn(['root'])];
    }

    /**
     * @return array<string, mixed>
     */
    private static function env(): array
    {
        return [
            'env' => ['nullable', 'array', 'max:50'],
            'env.*.key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/', 'distinct'],
            'env.*.value' => ['nullable', 'string', 'max:4096'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function servers(SiteData $site): array
    {
        return [
            'server_ids' => ['nullable', 'array'],
            'server_ids.*' => ['string', Rule::in($site->serverIds())],
        ];
    }
}
