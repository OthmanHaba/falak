<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Kiln\Alerting\Contracts\Severity;
use Kiln\Alerting\Domain\Enums\AlertOutcome;
use Kiln\Alerting\Domain\Enums\ChannelType;
use Kiln\Alerting\Domain\Enums\DeliveryStatus;
use Kiln\Alerting\Domain\Models\Alert;
use Kiln\Alerting\Domain\Models\Channel;
use Kiln\Alerting\Domain\Models\Notification;
use Kiln\Insights\Application\Actions\AssignIssue;
use Kiln\Insights\Application\Actions\ChangeIssueStatus;
use Kiln\Insights\Application\Actions\CommentOnIssue;
use Kiln\Insights\Application\Actions\EvaluateThresholds;
use Kiln\Insights\Application\Actions\IngestInsights;
use Kiln\Insights\Application\Actions\SaveThreshold;
use Kiln\Insights\Application\Actions\SetIssuePriority;
use Kiln\Insights\Application\HeartbeatTracker;
use Kiln\Insights\Contracts\IssuePriority;
use Kiln\Insights\Contracts\IssueStatus;
use Kiln\Insights\Domain\Models\HeartbeatMonitor;
use Kiln\Insights\Domain\Models\Issue;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Sites\Domain\Models\Site;

/**
 * Observability demo data for the UI (called by UiDemoSeeder): a week of request/job/query aggregates with a daily
 * rhythm and a recent incident, grouped exceptions with stack traces, scheduled-task heartbeats (one missed, one
 * failing), a threshold breach, issue triage (assignee, priority, comments, resolved/ignored), alert history and
 * in-app notifications. Goes through the real ingest pipeline. Local/demo only.
 */
class ObservabilityDemoSeeder extends Seeder
{
    private const AGENT = '01J8AGENT0000000000000DEMO';

    public function run(string $organizationId, string $userId): void
    {
        mt_srand(2026);
        $now = CarbonImmutable::now('UTC')->startOfMinute();
        $sites = Site::query()->where('organization_id', $organizationId)->with('targets')->get()->keyBy('slug');
        $storefront = $sites->get('storefront');
        $marketing = $sites->get('marketing');

        if ($storefront === null || $marketing === null) {
            return;
        }

        $serverOf = fn (Site $site) => (string) ($site->targets->first()?->server_id ?? Server::query()->where('organization_id', $organizationId)->value('id'));
        $ingest = app(IngestInsights::class);

        foreach ([[$storefront, $this->storefrontShape()], [$marketing, $this->marketingShape()]] as [$site, $shape]) {
            foreach (array_chunk($this->aggregates($site->id, $shape, $now), 2000) as $chunk) {
                $ingest($organizationId, $serverOf($site), self::AGENT, $chunk);
            }
        }

        $ingest($organizationId, $serverOf($storefront), self::AGENT, $this->exceptions($storefront->id, $marketing->id, $now));
        $this->heartbeats($organizationId, $serverOf($storefront), $storefront->id, $now);
        $this->performanceIssue($organizationId, $storefront->id, $userId);
        $this->triage($organizationId, $userId);
        $this->alerts($organizationId, $userId, $now);
    }

    /**
     * Minute buckets for the last 2 hours, 5-minute buckets for the rest of the day, hourly for the week before.
     *
     * @param  array<string, array{0: string, 1: float, 2: float, 3: float}>  $shape  name => [event type, calls/min, p95 ms, error ratio]
     * @return list<array<string, mixed>>
     */
    private function aggregates(string $siteId, array $shape, CarbonImmutable $now): array
    {
        $items = [];
        $buckets = [];

        for ($m = 120; $m >= 1; $m--) {
            $buckets[] = [$now->subMinutes($m), 1];
        }
        for ($m = 1440; $m > 120; $m -= 5) {
            $buckets[] = [$now->subMinutes($m), 5];
        }
        for ($h = 24 * 7; $h > 24; $h--) {
            $buckets[] = [$now->subHours($h), 60];
        }

        foreach ($buckets as [$at, $width]) {
            // Daily rhythm (quiet at night UTC) and an incident ~3h ago: errors and latency spike for 20 minutes.
            $hour = (int) $at->format('G') + (int) $at->format('i') / 60;
            $rhythm = 0.35 + 0.65 * (0.5 + 0.5 * sin(($hour - 8) / 24 * 2 * M_PI));
            $minutesAgo = $now->diffInMinutes($at, true);
            $incident = $minutesAgo >= 170 && $minutesAgo <= 190;

            foreach ($shape as $name => [$type, $perMinute, $p95, $errorRatio]) {
                $count = (int) max(0, round($perMinute * $width * $rhythm * (0.85 + mt_rand(0, 30) / 100)));

                if ($count === 0) {
                    continue;
                }

                $latency = $p95 * (0.8 + mt_rand(0, 40) / 100) * ($incident && $type !== 'cache' ? 3.2 : 1);
                $errors = (int) round($count * $errorRatio * ($incident ? 12 : 1) * (mt_rand(0, 200) / 100));

                $items[] = [
                    'kind' => 'aggregate',
                    'site_id' => strtoupper($siteId),
                    'event_type' => $type,
                    'name' => $name,
                    'count' => $count,
                    'errors' => min($count, $errors),
                    'p50_ms' => round($latency * 0.45, 1),
                    'p95_ms' => round($latency, 1),
                    'max_ms' => round($latency * (1.6 + mt_rand(0, 150) / 100), 1),
                    'minute' => $at->toIso8601ZuluString(),
                ];
            }
        }

        return $items;
    }

    /**
     * @return array<string, array{0: string, 1: float, 2: float, 3: float}>
     */
    private function storefrontShape(): array
    {
        return [
            'GET /' => ['request', 42, 85, 0.0005],
            'GET /products/{product}' => ['request', 36, 140, 0.001],
            'GET /search' => ['request', 14, 620, 0.002],
            'POST /cart/items' => ['request', 9, 180, 0.003],
            'POST /checkout' => ['request', 2.2, 1450, 0.018],
            'GET /account/orders' => ['request', 3.5, 310, 0.001],
            'GET /api/stock/{sku}' => ['request', 18, 45, 0.0008],
            'select * from `products` where `slug` = ? limit 1' => ['query', 60, 4, 0],
            'select * from `orders` where `user_id` = ? order by `created_at` desc' => ['query', 4, 38, 0],
            'select count(*) from `order_items` inner join `products` on …' => ['query', 1.5, 910, 0],
            'update `inventory` set `reserved` = `reserved` + ? where `sku` = ?' => ['query', 9, 22, 0],
            'App\\Jobs\\SendOrderConfirmation' => ['job', 2, 820, 0.004],
            'App\\Jobs\\SyncInventory' => ['job', 0.8, 5400, 0.02],
            'App\\Jobs\\GenerateInvoicePdf' => ['job', 1.6, 2300, 0.006],
            'Illuminate\\Notifications\\SendQueuedNotifications' => ['job', 3, 260, 0.001],
            'POST https://api.stripe.com/v1/payment_intents' => ['outgoing_request', 2.2, 690, 0.012],
            'hit:redis' => ['cache', 120, 1, 0],
            'miss:redis' => ['cache', 14, 1, 0],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: float, 2: float, 3: float}>
     */
    private function marketingShape(): array
    {
        return [
            'GET /' => ['request', 28, 110, 0.0004],
            'GET /blog/{slug}' => ['request', 16, 190, 0.0008],
            'GET /pricing' => ['request', 6, 95, 0.0002],
            'POST /api/newsletter' => ['request', 0.6, 380, 0.01],
            'GET /_next/image' => ['request', 40, 60, 0.0006],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exceptions(string $storefront, string $marketing, CarbonImmutable $now): array
    {
        $release = '/srv/kiln/sites/storefront/releases/01J8REL0000000000000000000';
        $vendor = [
            "{$release}/vendor/laravel/framework/src/Illuminate/Routing/Controller.php(54): Illuminate\\Routing\\Controller->callAction()",
            "{$release}/vendor/laravel/framework/src/Illuminate/Routing/ControllerDispatcher.php(43): Illuminate\\Routing\\ControllerDispatcher->dispatch()",
            "{$release}/vendor/laravel/framework/src/Illuminate/Routing/Route.php(265): Illuminate\\Routing\\Route->runController()",
            "{$release}/vendor/laravel/framework/src/Illuminate/Routing/Router.php(808): Illuminate\\Routing\\Router->runRouteWithinStack()",
            "{$release}/vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php(169): Illuminate\\Pipeline\\Pipeline->then()",
            "{$release}/vendor/laravel/framework/src/Illuminate/Foundation/Http/Kernel.php(200): Illuminate\\Foundation\\Http\\Kernel->sendRequestThroughRouter()",
        ];
        $trace = function (array $app) use ($vendor, $release) {
            $lines = [];
            foreach ([...array_map(fn ($frame) => "{$release}/{$frame}", $app), ...$vendor] as $index => $frame) {
                $lines[] = "#{$index} {$frame}";
            }
            $lines[] = '#'.count($lines).' {main}';

            return implode("\n", $lines);
        };

        $kinds = [
            // [site, type, message, app frames, handled, event type, route, occurrences, window minutes, users]
            [$storefront, 'App\\Exceptions\\PaymentDeclined', 'Card declined: insufficient funds (payment_intent pi_3Q…)', [
                'app/Services/Payments/StripeGateway.php(88): Stripe\\Service\\PaymentIntentService->confirm()',
                'app/Services/Checkout/PlaceOrder.php(54): App\\Services\\Payments\\StripeGateway->charge()',
                'app/Http/Controllers/CheckoutController.php(31): App\\Services\\Checkout\\PlaceOrder->__invoke()',
            ], false, 'request', 'POST /checkout', 46, 180, 31],
            [$storefront, 'Illuminate\\Database\\QueryException', 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock (update `inventory` …)', [
                'app/Actions/ReserveStock.php(27): Illuminate\\Database\\Query\\Builder->update()',
                'app/Http/Controllers/CartItemController.php(19): App\\Actions\\ReserveStock->__invoke()',
            ], false, 'request', 'POST /cart/items', 18, 200, 12],
            [$storefront, 'Illuminate\\Database\\Eloquent\\ModelNotFoundException', 'No query results for model [App\\Models\\Product] discontinued-lamp', [
                'app/Http/Controllers/ProductController.php(22): Illuminate\\Database\\Eloquent\\Builder->firstOrFail()',
            ], true, 'request', 'GET /products/{product}', 64, 1400, 40],
            [$storefront, 'GuzzleHttp\\Exception\\ConnectException', 'cURL error 28: Operation timed out after 10001 milliseconds (warehouse.acme.test)', [
                'app/Services/Warehouse/Client.php(61): GuzzleHttp\\Client->get()',
                'app/Jobs/SyncInventory.php(34): App\\Services\\Warehouse\\Client->stock()',
            ], false, 'job', 'App\\Jobs\\SyncInventory', 9, 900, 0],
            [$storefront, 'ErrorException', 'Undefined array key "vat_id"', [
                'app/Support/Invoices/InvoiceData.php(112): Illuminate\\Foundation\\Bootstrap\\HandleExceptions->handleError()',
                'app/Jobs/GenerateInvoicePdf.php(40): App\\Support\\Invoices\\InvoiceData::fromOrder()',
            ], false, 'job', 'App\\Jobs\\GenerateInvoicePdf', 5, 5000, 0],
            [$marketing, 'App\\Exceptions\\NewsletterProviderError', 'Mailcoach API returned 429 Too Many Requests', [
                'app/Newsletter/Subscribe.php(33): App\\Newsletter\\MailcoachClient->subscribe()',
            ], true, 'request', 'POST /api/newsletter', 7, 700, 7],
        ];

        $items = [];

        foreach ($kinds as [$site, $type, $message, $frames, $handled, $eventType, $route, $count, $window, $users]) {
            for ($i = 0; $i < $count; $i++) {
                // Bias occurrences towards the recent incident for the checkout/deadlock errors.
                $minutesAgo = $window <= 200 ? 170 + mt_rand(0, 25) - ($i % 4 === 0 ? mt_rand(0, 150) : 0) : mt_rand(1, $window);
                $items[] = [
                    'kind' => 'exception',
                    'trace_id' => bin2hex(random_bytes(16)),
                    'span_id' => bin2hex(random_bytes(8)),
                    'site_id' => strtoupper($site),
                    'type' => $type,
                    'message' => $message,
                    'stacktrace' => $trace($frames),
                    'handled' => $handled,
                    'user_id' => $users > 0 ? 'user-'.mt_rand(1, $users) : null,
                    'event_type' => $eventType,
                    'route_or_name' => $route,
                    'at' => $now->subMinutes(max(1, $minutesAgo))->subSeconds(mt_rand(0, 59))->toIso8601ZuluString(),
                ];
            }
        }

        return $items;
    }

    private function heartbeats(string $organizationId, string $serverId, string $siteId, CarbonImmutable $now): void
    {
        $ingest = app(IngestInsights::class);
        $jobs = [
            // job, schedule, period minutes, duration ms, fail every Nth, stop reporting N minutes ago
            ['storefront-schedule-run', '* * * * *', 1, 900, 0, 0],
            ['storefront:prune-carts', '0 * * * *', 60, 4200, 0, 0],
            ['storefront:sync-inventory', '*/15 * * * *', 15, 38_000, 7, 0],
            ['storefront:send-abandoned-cart-emails', '*/30 * * * *', 30, 12_000, 0, 150],
            ['storefront:backup-database', '0 3 * * *', 1440, 184_000, 0, 0],
        ];
        $items = [];

        foreach ($jobs as [$job, $schedule, $period, $duration, $failEvery, $stoppedAgo]) {
            $slots = intdiv(26 * 60, $period);
            $minuteOfDay = (int) $now->format('G') * 60 + (int) $now->format('i');
            $start = $period === 1440
                ? ($now->startOfDay()->addHours(3)->greaterThan($now) ? $now->startOfDay()->subDay()->addHours(3) : $now->startOfDay()->addHours(3))
                : $now->startOfDay()->addMinutes(intdiv($minuteOfDay - 1, $period) * $period);

            for ($i = $slots; $i >= 0; $i--) {
                $scheduled = $start->subMinutes($i * $period);

                if ($scheduled->greaterThan($now->subMinutes(max(1, $stoppedAgo))) || $scheduled->lessThan($now->subHours(26))) {
                    continue;
                }

                $failed = $failEvery > 0 && $i % $failEvery === 0;
                $items[] = [
                    'kind' => 'cron_heartbeat',
                    'job' => $job,
                    'site_id' => strtoupper($siteId),
                    'schedule' => $schedule,
                    'status' => $failed ? 'failed' : 'finished',
                    'exit_code' => $failed ? 1 : 0,
                    'duration_ms' => (int) round($duration * (0.8 + mt_rand(0, 40) / 100)),
                    'scheduled_at' => $scheduled->toIso8601ZuluString(),
                    'at' => $scheduled->addMilliseconds((int) round($duration))->toIso8601ZuluString(),
                ];
            }
        }

        // Heartbeats arrive in order, like the agent sends them.
        usort($items, fn ($a, $b) => strcmp($a['at'], $b['at']));

        foreach (array_chunk($items, 500) as $chunk) {
            $ingest($organizationId, $serverId, self::AGENT, $chunk);
        }

        // Monitors were first seen days ago (so the last 24h all count as expected), then detect missed runs.
        HeartbeatMonitor::query()->where('organization_id', $organizationId)->update(['created_at' => $now->subDays(3)]);
        app(HeartbeatTracker::class)->detectMissed($now);
    }

    private function performanceIssue(string $organizationId, string $siteId, string $userId): void
    {
        app(SaveThreshold::class)($organizationId, $siteId, [
            'event_type' => 'request',
            'name_pattern' => 'GET /search',
            'metric' => 'p95',
            'threshold_ms' => 500,
            'window_minutes' => 15,
            'min_count' => 10,
            'enabled' => true,
        ], $userId);
        app(EvaluateThresholds::class)();
    }

    private function triage(string $organizationId, string $userId): void
    {
        $issue = fn (string $type) => Issue::query()->where('organization_id', $organizationId)->where('exception_type', $type)->first();

        if ($payment = $issue('App\\Exceptions\\PaymentDeclined')) {
            app(AssignIssue::class)($payment, $userId, $userId);
            app(SetIssuePriority::class)($payment, IssuePriority::Urgent, $userId);
            app(CommentOnIssue::class)($payment, $userId, "Spike started right after the 14:02 deploy — Stripe returns `insufficient_funds` but we retry the confirm twice. Looking at PlaceOrder.\n\nRollback candidate: 01J8REL…");
        }

        if ($deadlock = $issue('Illuminate\\Database\\QueryException')) {
            app(SetIssuePriority::class)($deadlock, IssuePriority::High, $userId);
        }

        if ($missing = $issue('Illuminate\\Database\\Eloquent\\ModelNotFoundException')) {
            app(ChangeIssueStatus::class)($missing, IssueStatus::Ignored, $userId);
        }

        if ($newsletter = $issue('App\\Exceptions\\NewsletterProviderError')) {
            app(SetIssuePriority::class)($newsletter, IssuePriority::Low, $userId);
            app(ChangeIssueStatus::class)($newsletter, IssueStatus::Resolved, $userId);
        }
    }

    private function alerts(string $organizationId, string $userId, CarbonImmutable $now): void
    {
        $issues = Issue::query()->where('organization_id', $organizationId)->orderByDesc('last_seen_at')->get();
        $url = fn (?Issue $issue) => $issue ? '/observability/issues/'.$issue->id : '/observability';
        $payment = $issues->firstWhere('exception_type', 'App\\Exceptions\\PaymentDeclined');
        $deadlock = $issues->firstWhere('exception_type', 'Illuminate\\Database\\QueryException');
        $heartbeat = $issues->firstWhere('kind.value', 'heartbeat');

        $entries = [
            // minutes ago, type, severity, title, body, url, outcome, recovery, notify, read
            [178, 'insights.issue.opened', Severity::Critical, 'New issue on Storefront: PaymentDeclined', 'Card declined: insufficient funds · POST /checkout', $url($payment), AlertOutcome::Delivered, false, true, false],
            [176, 'insights.issue.opened', Severity::Warning, 'New issue on Storefront: QueryException', 'Deadlock found when trying to get lock · POST /cart/items', $url($deadlock), AlertOutcome::Delivered, false, true, false],
            [150, 'insights.threshold.breached', Severity::Warning, 'Storefront: GET /search p95 above 500 ms', 'p95 1.84 s over 15 minutes', '/observability/issues?kind=performance', AlertOutcome::RateLimited, false, true, true],
            [118, 'insights.heartbeat.missed', Severity::Critical, 'Missed scheduled task: storefront:send-abandoned-cart-emails', 'No heartbeat since its 30-minute slot', $url($heartbeat), AlertOutcome::Delivered, false, true, false],
            [95, 'deployments.deployment.failed', Severity::Critical, 'Deploy failed: Marketing (main@9f2c1ab)', 'npm run build exited with code 1 on app-2', '/projects', AlertOutcome::Delivered, false, true, true],
            [60, 'fleet.server.offline', Severity::Critical, 'Server edge-1 is offline', 'No agent heartbeat for 5 minutes', '/servers', AlertOutcome::QuietHours, false, false, false],
            [42, 'fleet.server.offline', Severity::Info, 'Server edge-1 is back online', null, '/servers', AlertOutcome::Deduplicated, true, false, false],
            [20, 'deployments.deployment.succeeded', Severity::Info, 'Deployed Storefront (main@4be09d2)', 'Rolled out to app-1, app-2 in 48s', '/projects', AlertOutcome::NoRoute, false, true, true],
            [60 * 26, 'certificates.expiring', Severity::Warning, 'TLS certificate for shop.acme.test expires in 12 days', 'Automatic renewal failed: DNS-01 challenge timed out', '/projects', AlertOutcome::Delivered, false, true, true],
        ];

        // Reuse the channels SettingsDemoSeeder created (same names), or create them.
        $slack = Channel::query()->firstOrCreate(
            ['organization_id' => $organizationId, 'name' => '#ops-alerts'],
            ['type' => ChannelType::Slack, 'config' => ['webhook_url' => 'https://hooks.slack.com/services/T000/B000/demo'], 'enabled' => true, 'last_sent_at' => $now->subMinutes(118)],
        );
        $pager = Channel::query()->firstOrCreate(
            ['organization_id' => $organizationId, 'name' => 'PagerDuty bridge'],
            ['type' => ChannelType::Webhook, 'config' => ['url' => 'https://events.pagerduty.example/v2/enqueue'], 'enabled' => true, 'last_error' => 'HTTP 503 from events.pagerduty.example'],
        );

        foreach ($entries as [$ago, $type, $severity, $title, $body, $link, $outcome, $recovery, $notify, $read]) {
            $at = $now->subMinutes($ago);
            $alert = Alert::query()->create([
                'organization_id' => $organizationId,
                'type' => $type,
                'severity' => $severity,
                'title' => $title,
                'body' => $body,
                'url' => $link,
                'recovery' => $recovery,
                'context' => [],
                'outcome' => $outcome,
                'matched_rule_ids' => [],
                'created_at' => $at,
            ]);

            if ($outcome === AlertOutcome::Delivered) {
                $alert->deliveries()->create(['channel_id' => $slack->id, 'status' => DeliveryStatus::Sent, 'attempts' => 1, 'sent_at' => $at->addSeconds(2)]);

                if ($severity === Severity::Critical) {
                    $failed = $ago === 95;
                    $alert->deliveries()->create(['channel_id' => $pager->id, 'status' => $failed ? DeliveryStatus::Failed : DeliveryStatus::Sent, 'attempts' => $failed ? 3 : 1, 'error' => $failed ? 'HTTP 503 from events.pagerduty.example' : null, 'sent_at' => $failed ? null : $at->addSeconds(3)]);
                }
            }

            if ($notify) {
                Notification::query()->create([
                    'organization_id' => $organizationId,
                    'user_id' => $userId,
                    'type' => $type,
                    'severity' => $severity,
                    'title' => $title,
                    'body' => $body,
                    'url' => $link,
                    'read_at' => $read ? $at->addMinutes(5) : null,
                    'created_at' => $at,
                ]);
            }
        }
    }
}
