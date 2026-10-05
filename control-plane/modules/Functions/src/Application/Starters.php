<?php

namespace Falak\Functions\Application;

use Illuminate\Validation\ValidationException;

/**
 * The code a new function starts with: resources/starters/<family>/<key>.<ext>, one per runtime family (ts is
 * shared by Bun, Node and Deno; python; go). A starter can declare the variables it reads (created with the function,
 * `generate` ones filled with a random secret) and a schedule.
 */
final class Starters
{
    /**
     * @var array<string, array{title: string, description: string, category: string, variables?: array<string, array{hint: string, value?: string, generate?: bool}>, schedule?: array{name: string, expression: string}}>
     */
    public const ALL = [
        'hello' => [
            'title' => 'Hello API',
            'description' => 'A minimal HTTP API with two routes.',
            'category' => 'Basics',
        ],
        'postgres-api' => [
            'title' => 'JSON API + Postgres',
            'description' => 'A notes CRUD API on a database.',
            'category' => 'Data',
            'variables' => ['DATABASE_URL' => ['hint' => 'Postgres URL, e.g. ${{ postgres.DATABASE_URL }}']],
        ],
        'webhook' => [
            'title' => 'Signed webhook receiver',
            'description' => 'Verifies HMAC-SHA256 signed webhooks (GitHub style).',
            'category' => 'Webhooks',
            'variables' => ['WEBHOOK_SECRET' => ['hint' => 'Shared signing secret', 'generate' => true]],
        ],
        'stripe-webhook' => [
            'title' => 'Stripe webhooks',
            'description' => 'Verifies Stripe-Signature and handles checkout, invoice and subscription events.',
            'category' => 'Webhooks',
            'variables' => ['STRIPE_WEBHOOK_SECRET' => ['hint' => 'Signing secret of the endpoint (whsec_…)']],
        ],
        'telegram-bot' => [
            'title' => 'Telegram bot',
            'description' => 'A webhook bot with commands; /setup registers it with Telegram.',
            'category' => 'Bots',
            'variables' => [
                'TELEGRAM_BOT_TOKEN' => ['hint' => 'Token from @BotFather'],
                'TELEGRAM_WEBHOOK_SECRET' => ['hint' => 'Telegram sends it back on every update', 'generate' => true],
            ],
        ],
        'slack-notify' => [
            'title' => 'Slack / Discord notifier',
            'description' => 'POST a message with a token; it is posted to Slack and/or Discord.',
            'category' => 'Notifications',
            'variables' => [
                'NOTIFY_TOKEN' => ['hint' => 'Callers send Authorization: Bearer <token>', 'generate' => true],
                'SLACK_WEBHOOK_URL' => ['hint' => 'Slack incoming webhook (optional)'],
                'DISCORD_WEBHOOK_URL' => ['hint' => 'Discord channel webhook (optional)'],
            ],
        ],
        'scheduled' => [
            'title' => 'Scheduled job',
            'description' => 'A scheduled() handler that runs every hour, plus HTTP.',
            'category' => 'Scheduled',
            'schedule' => ['name' => 'Hourly', 'expression' => '@hourly'],
        ],
        'uptime-monitor' => [
            'title' => 'Uptime monitor',
            'description' => 'Checks your URLs every 5 minutes and alerts Slack or Discord when one is down.',
            'category' => 'Scheduled',
            'variables' => [
                'URLS' => ['hint' => 'Comma-separated URLs to check'],
                'ALERT_WEBHOOK_URL' => ['hint' => 'Slack or Discord webhook for alerts (optional)'],
            ],
            'schedule' => ['name' => 'Every 5 minutes', 'expression' => '*/5 * * * *'],
        ],
        'api-proxy' => [
            'title' => 'Caching API proxy',
            'description' => 'Fronts another API: hides its key, adds CORS and caches GET responses.',
            'category' => 'APIs',
            'variables' => [
                'UPSTREAM_URL' => ['hint' => 'e.g. https://api.example.com'],
                'UPSTREAM_AUTHORIZATION' => ['hint' => 'Authorization header sent upstream (optional)'],
                'CACHE_SECONDS' => ['hint' => 'GET cache lifetime', 'value' => '60'],
                'ALLOWED_ORIGIN' => ['hint' => 'CORS origin', 'value' => '*'],
            ],
        ],
        'contact-form' => [
            'title' => 'Contact form → email',
            'description' => 'Your site posts its form here; messages are emailed through Resend, with a spam honeypot.',
            'category' => 'Forms',
            'variables' => [
                'RESEND_API_KEY' => ['hint' => 'Resend API key'],
                'CONTACT_TO' => ['hint' => 'Where messages go'],
                'CONTACT_FROM' => ['hint' => 'Sender on a domain verified in Resend'],
                'ALLOWED_ORIGIN' => ['hint' => "Your site's origin for CORS", 'value' => '*'],
                'REDIRECT_URL' => ['hint' => 'Where plain form posts go afterwards (optional)'],
            ],
        ],
    ];

    private const EXTENSIONS = ['ts' => 'ts', 'python' => 'py', 'go' => 'go'];

    /**
     * @return list<array{key: string, title: string, description: string, category: string, variables: list<string>, schedule: ?array{name: string, expression: string}, families: list<string>}>
     */
    public static function list(): array
    {
        $out = [];

        foreach (self::ALL as $key => $starter) {
            $out[] = [
                'key' => $key,
                'title' => $starter['title'],
                'description' => $starter['description'],
                'category' => $starter['category'],
                'variables' => array_keys($starter['variables'] ?? []),
                'schedule' => $starter['schedule'] ?? null,
                'families' => array_values(array_filter(array_keys(self::EXTENSIONS), fn (string $family) => is_file(self::path($family, $key)))),
            ];
        }

        return $out;
    }

    /**
     * @throws ValidationException
     */
    public static function content(string $key, string $family = 'ts'): string
    {
        if (! array_key_exists($key, self::ALL) || ! is_file(self::path($family, $key))) {
            throw ValidationException::withMessages(['starter' => 'This starter is not available for the runtime.']);
        }

        return (string) file_get_contents(self::path($family, $key));
    }

    /**
     * The variables a starter reads, with their initial values (generated secrets for `generate`).
     *
     * @return array<string, string>
     */
    public static function variables(string $key): array
    {
        $out = [];

        foreach (self::ALL[$key]['variables'] ?? [] as $name => $variable) {
            $out[$name] = ($variable['generate'] ?? false) ? bin2hex(random_bytes(24)) : ($variable['value'] ?? '');
        }

        return $out;
    }

    private static function path(string $family, string $key): string
    {
        return dirname(__DIR__, 2)."/resources/starters/{$family}/{$key}.".(self::EXTENSIONS[$family] ?? 'ts');
    }
}
