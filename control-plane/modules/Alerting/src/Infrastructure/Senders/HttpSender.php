<?php

namespace Falak\Alerting\Infrastructure\Senders;

use Falak\Alerting\Application\AlertMessage;
use Falak\Alerting\Contracts\Severity;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

abstract class HttpSender implements ChannelSender
{
    public function mask(array $config): array
    {
        $masked = $config;

        foreach ($this->secretKeys() as $key) {
            if (isset($config[$key]) && is_string($config[$key]) && $config[$key] !== '') {
                $masked[$key] = self::maskValue($config[$key]);
            }
        }

        return $masked;
    }

    public static function maskValue(string $value): string
    {
        if (preg_match('#^(https?://[^/]+)/#', $value, $m) === 1) {
            return $m[1].'/…/'.substr($value, -4);
        }

        return '••••'.(strlen($value) > 12 ? substr($value, -4) : '');
    }

    /**
     * POST and require a 2xx response.
     *
     * @param  array<string, mixed>|string  $body  array = JSON, string = raw JSON body
     * @param  array<string, string>  $headers
     * @param  list<string>  $secrets  values redacted from error messages
     *
     * @throws DeliveryFailed
     */
    protected function post(string $url, array|string $body, array $headers = [], array $secrets = []): Response
    {
        $request = Http::timeout((int) config('alerting.http_timeout', 10))
            ->connectTimeout(5)
            ->acceptJson()
            ->withHeaders(['User-Agent' => 'Falak-Alerting/1.0', ...$headers]);

        try {
            $response = is_string($body)
                ? $request->withBody($body, 'application/json')->post($url)
                : $request->post($url, $body);
        } catch (ConnectionException $e) {
            throw new DeliveryFailed($this->redact('Connection failed: '.$e->getMessage(), [$url, ...$secrets]));
        }

        if (! $response->successful()) {
            $detail = Str::limit(trim(strip_tags($response->body())), 300);

            throw new DeliveryFailed($this->redact("HTTP {$response->status()}".($detail !== '' ? ": {$detail}" : ''), [$url, ...$secrets]));
        }

        return $response;
    }

    /**
     * @param  list<string>  $secrets
     */
    protected function redact(string $message, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $message = str_replace($secret, '[redacted]', $message);
            }
        }

        return $message;
    }

    protected static function color(AlertMessage $message): int
    {
        if ($message->resolved) {
            return 0x10B981;
        }

        return match ($message->severity) {
            Severity::Critical => 0xDC2626,
            Severity::Warning => 0xF59E0B,
            Severity::Info => 0x3B82F6,
        };
    }
}
