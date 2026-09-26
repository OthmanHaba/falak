<?php

use Illuminate\Support\Str;
use Kiln\Insights\Application\Actions\IngestInsights;

const INSIGHTS_SITE = '01j8sxte0000000000000000aa';
const INSIGHTS_OTHER_SITE = '01j8sxte0000000000000000bb';

/**
 * @return array<string, mixed> an `exception` insight line (contracts/telemetry)
 */
function insights_exception(array $overrides = []): array
{
    $line = $overrides['line'] ?? 42;
    unset($overrides['line']);

    return array_replace([
        'kind' => 'exception',
        'trace_id' => bin2hex(random_bytes(16)),
        'span_id' => bin2hex(random_bytes(8)),
        'site_id' => INSIGHTS_SITE,
        'type' => 'App\\Exceptions\\PaymentFailed',
        'message' => 'Card declined',
        'stacktrace' => implode("\n", [
            "#0 /srv/kiln/sites/shop/releases/01J8REL0000000000000000000/app/Services/Checkout.php({$line}): App\\Services\\Gateway->charge()",
            '#1 /srv/kiln/sites/shop/releases/01J8REL0000000000000000000/app/Http/Controllers/CheckoutController.php(20): App\\Services\\Checkout->pay()',
            '#2 /srv/kiln/sites/shop/releases/01J8REL0000000000000000000/vendor/laravel/framework/src/Illuminate/Routing/Controller.php(54): call_user_func_array()',
            '#3 {main}',
        ]),
        'handled' => false,
        'user_id' => 'user-1',
        'event_type' => 'request',
        'route_or_name' => 'POST /checkout',
        'at' => now()->toIso8601ZuluString(),
    ], $overrides);
}

/**
 * @return array<string, mixed> an `aggregate` insight line
 */
function insights_aggregate(array $overrides = []): array
{
    return array_replace([
        'kind' => 'aggregate',
        'site_id' => INSIGHTS_SITE,
        'event_type' => 'request',
        'name' => 'GET /products',
        'count' => 100,
        'p50_ms' => 40,
        'p95_ms' => 120,
        'max_ms' => 300,
        'errors' => 1,
        'minute' => now()->subMinute()->startOfMinute()->toIso8601ZuluString(),
    ], $overrides);
}

/**
 * @return array<string, mixed> a `cron_heartbeat` line (cron.apply $defs.heartbeat)
 */
function insights_heartbeat(array $overrides = []): array
{
    return array_replace([
        'kind' => 'cron_heartbeat',
        'job' => 'shop-schedule',
        'site_id' => INSIGHTS_SITE,
        'schedule' => '*/5 * * * *',
        'status' => 'finished',
        'exit_code' => 0,
        'duration_ms' => 850,
        'scheduled_at' => now()->startOfMinute()->toIso8601ZuluString(),
        'at' => now()->toIso8601ZuluString(),
    ], $overrides);
}

/**
 * Run the ingest action as if an agent of $organizationId posted $items.
 *
 * @return array{exceptions: int, aggregates: int, heartbeats: int, skipped: int}
 */
function insights_ingest(string $organizationId, array $items, ?string $serverId = '01J8SERVER0000000000000000', string $agentId = '01J8AGENT00000000000000000'): array
{
    return app(IngestInsights::class)($organizationId, $serverId, $agentId, $items);
}

function insights_ulid(): string
{
    return (string) Str::ulid();
}
