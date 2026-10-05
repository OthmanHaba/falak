<?php

namespace Falak\Processes\Contracts;

use Falak\Processes\Http\Requests\ProcessRules;

/**
 * The schedule expressions the agent's scheduler runs: 5-field cron, @hourly … @yearly, `@every <duration>`.
 * An expression the agent cannot parse fails the whole cron.apply of a server, so validate before saving.
 */
final class CronExpressions
{
    public const HINT = 'Use a 5-field cron expression, @hourly, @daily, @weekly, @monthly, @yearly or "@every 5m".';

    public static function valid(string $expression): bool
    {
        return ProcessRules::validExpression($expression);
    }
}
