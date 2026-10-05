<?php

namespace Falak\Telemetry\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

trait ResolvesTimeRange
{
    /** @var array<string, int> */
    private static array $ranges = ['15m' => 900, '1h' => 3600, '6h' => 21600, '24h' => 86400, '7d' => 604800];

    /**
     * @return array<string, mixed>
     */
    protected function timeRules(): array
    {
        return [
            'range' => ['nullable', Rule::in(array_keys(self::$ranges))],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function timeRange(Request $request, string $default = '1h', int $maxSeconds = 30 * 86400): array
    {
        $to = $request->filled('to') ? CarbonImmutable::parse((string) $request->input('to')) : CarbonImmutable::now();
        $from = $request->filled('from')
            ? CarbonImmutable::parse((string) $request->input('from'))
            : $to->subSeconds(self::$ranges[(string) $request->input('range', $default)] ?? self::$ranges[$default]);

        if ($from->greaterThanOrEqualTo($to)) {
            throw ValidationException::withMessages(['from' => 'The start must be before the end.']);
        }

        if ($from->diffInSeconds($to) > $maxSeconds) {
            throw ValidationException::withMessages(['from' => 'The time range is too large.']);
        }

        return [$from, $to];
    }
}
