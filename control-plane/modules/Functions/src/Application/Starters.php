<?php

namespace Kiln\Functions\Application;

use Illuminate\Validation\ValidationException;

/**
 * The code a new function starts with (resources/starters/<key>.ts).
 */
final class Starters
{
    /** @var array<string, array{title: string, description: string}> */
    public const ALL = [
        'hello' => ['title' => 'Hello Hono', 'description' => 'A minimal HTTP API with two routes.'],
        'postgres-api' => ['title' => 'JSON API + Postgres', 'description' => 'Notes API on a database (DATABASE_URL, Bun’s built-in client).'],
        'webhook' => ['title' => 'Webhook receiver', 'description' => 'Verifies HMAC-signed webhooks (GitHub, Stripe style).'],
    ];

    /**
     * @return list<array{key: string, title: string, description: string}>
     */
    public static function list(): array
    {
        return array_map(fn (string $key, array $starter) => ['key' => $key, ...$starter], array_keys(self::ALL), self::ALL);
    }

    /**
     * @throws ValidationException
     */
    public static function content(string $key): string
    {
        if (! array_key_exists($key, self::ALL)) {
            throw ValidationException::withMessages(['starter' => 'Unknown starter.']);
        }

        return (string) file_get_contents(dirname(__DIR__, 2)."/resources/starters/{$key}.ts");
    }
}
