<?php

namespace Falak\Alerting\Infrastructure\Senders;

use Closure;
use Falak\Alerting\Application\AlertMessage;
use Falak\Alerting\Domain\Enums\ChannelType;

/**
 * Generic JSON webhook signed with HMAC-SHA256. Config: {url, secret}.
 *
 * Receivers verify: `X-Falak-Signature == "sha256=" . hmac_sha256(secret, X-Falak-Timestamp . "." . raw_body)`
 * and reject stale timestamps.
 */
final class WebhookSender extends HttpSender
{
    public function type(): ChannelType
    {
        return ChannelType::Webhook;
    }

    public function rules(): array
    {
        return [
            'config.url' => ['required', 'string', 'max:2000', 'url:http,https', $this->publicHost()],
            'config.secret' => ['required', 'string', 'min:16', 'max:255'],
        ];
    }

    public function secretKeys(): array
    {
        return ['url', 'secret'];
    }

    public function send(array $config, AlertMessage $message): void
    {
        $url = (string) $config['url'];
        $secret = (string) $config['secret'];

        if (! self::isAllowedHost($url)) {
            throw new DeliveryFailed('The webhook URL points to a private or loopback address.');
        }

        $body = (string) json_encode($message->toPayload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->getTimestamp();

        $this->post($url, $body, [
            'X-Falak-Event' => $message->type,
            'X-Falak-Delivery' => $message->id,
            'X-Falak-Timestamp' => $timestamp,
            'X-Falak-Signature' => self::signature($secret, $timestamp, $body),
        ], [$secret]);
    }

    public static function signature(string $secret, string $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /**
     * Reject loopback, private, link-local and reserved targets (literal IPs and well-known local names).
     * DNS is not resolved here; see the module notes on DNS rebinding.
     */
    public static function isAllowedHost(string $url): bool
    {
        if (config('alerting.allow_private_webhooks')) {
            return true;
        }

        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));

        if ($host === '' || $host === 'localhost' || preg_match('/\.(localhost|local|internal|localdomain|home\.arpa)$/', $host) === 1) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        // Bare integers / hex (e.g. http://2130706433) are resolved as IPs by some clients.
        return preg_match('/^(0x[0-9a-f]+|\d+)$/', $host) !== 1 && str_contains($host, '.');
    }

    private function publicHost(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (is_string($value) && ! self::isAllowedHost($value)) {
                $fail('The webhook URL must point to a public host.');
            }
        };
    }
}
