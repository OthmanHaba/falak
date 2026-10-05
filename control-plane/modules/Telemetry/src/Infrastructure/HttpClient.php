<?php

namespace Falak\Telemetry\Infrastructure;

use Falak\Telemetry\Contracts\Exceptions\TelemetryQueryFailed;
use Falak\Telemetry\Contracts\Exceptions\TelemetryUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Shared HTTP plumbing for the observability backends: base URL, timeouts, auth headers and
 * mapping of transport / HTTP failures onto the Telemetry contract exceptions.
 */
final class HttpClient
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly string $component,
        private readonly ?string $baseUrl,
        private readonly array $headers = [],
        private readonly ?string $token = null,
    ) {}

    public function configured(): bool
    {
        return $this->baseUrl !== null && trim($this->baseUrl) !== '';
    }

    public function request(): PendingRequest
    {
        if (! $this->configured()) {
            throw TelemetryUnavailable::notConfigured($this->component);
        }

        $request = Http::baseUrl(rtrim((string) $this->baseUrl, '/'))
            ->acceptJson()
            ->timeout((int) config('telemetry.http.timeout', 15))
            ->connectTimeout((int) config('telemetry.http.connect_timeout', 3))
            ->withHeaders(array_filter($this->headers, fn ($v) => $v !== null && $v !== ''));

        return $this->token ? $request->withToken($this->token) : $request;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function get(string $path, array $query = []): Response
    {
        return $this->send(fn (PendingRequest $r) => $r->get($path, $query));
    }

    /**
     * @param  callable(PendingRequest): Response  $send
     *
     * @throws TelemetryUnavailable
     */
    public function send(callable $send): Response
    {
        try {
            return $send($this->request());
        } catch (ConnectionException $e) {
            throw TelemetryUnavailable::unreachable($this->component, $e->getMessage());
        }
    }

    /**
     * Throw for non-2xx responses, extracting the backend's error message.
     *
     * @throws TelemetryQueryFailed|TelemetryUnavailable
     */
    public function ensureSuccessful(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }

        if (in_array($response->status(), [502, 503, 504], true)) {
            throw TelemetryUnavailable::unreachable($this->component, "HTTP {$response->status()}");
        }

        throw new TelemetryQueryFailed($this->component, self::errorMessage($response), $response->status());
    }

    public static function errorMessage(Response $response): string
    {
        $json = $response->json();

        if (is_array($json)) {
            $message = $json['error'] ?? $json['message'] ?? null;

            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        $body = trim($response->body());

        return $body !== '' ? mb_substr($body, 0, 500) : "HTTP {$response->status()}";
    }
}
