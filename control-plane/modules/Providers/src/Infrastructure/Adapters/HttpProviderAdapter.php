<?php

namespace Falak\Providers\Infrastructure\Adapters;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Falak\Providers\Contracts\Exceptions\ProviderException;
use Falak\Providers\Contracts\ProviderAdapter;
use Throwable;

/**
 * Shared HTTP plumbing: timeouts, retry policy and error mapping to ProviderException.
 *
 * Retries: 429 is retried for every method; 5xx and connection failures only for idempotent
 * methods (GET/DELETE/HEAD) so a create is never duplicated.
 */
abstract class HttpProviderAdapter implements ProviderAdapter
{
    /**
     * @param  array{timeout?: int, connect_timeout?: int, retries?: int, retry_sleep_ms?: int}  $http
     */
    public function __construct(protected readonly array $http = []) {}

    /** Authenticated request with the provider base URL. */
    abstract protected function client(): PendingRequest;

    /** Human readable message from a failed provider response. */
    abstract protected function errorMessage(Response $response): string;

    /**
     * @param  array<string, mixed>  $data  JSON body (POST/PUT/PATCH/DELETE) or query string (GET)
     */
    protected function call(string $method, string $path, array $data = [], bool $allowNotFound = false): ?Response
    {
        $method = strtoupper($method);
        $idempotent = in_array($method, ['GET', 'HEAD', 'DELETE'], true);

        $request = $this->prepare($this->client(), $idempotent);

        try {
            $response = match ($method) {
                'GET' => $request->get($path, $data),
                'POST' => $request->post($path, $data),
                'PUT' => $request->put($path, $data),
                'PATCH' => $request->patch($path, $data),
                'DELETE' => $request->delete($path, $data),
                default => throw new ProviderException("Unsupported HTTP method {$method}.", $this->type()->value),
            };
        } catch (ConnectionException $e) {
            throw new ProviderException("{$this->type()->label()} API is unreachable: {$e->getMessage()}", $this->type()->value, null, $e);
        }

        if ($allowNotFound && $response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            throw $this->exception($response);
        }

        return $response;
    }

    protected function prepare(PendingRequest $request, bool $idempotent): PendingRequest
    {
        $sleep = max(0, (int) ($this->http['retry_sleep_ms'] ?? 500));

        return $request
            ->acceptJson()
            ->timeout((int) ($this->http['timeout'] ?? 30))
            ->connectTimeout((int) ($this->http['connect_timeout'] ?? 10))
            ->retry(
                max(1, (int) ($this->http['retries'] ?? 3)),
                fn (int $attempt) => $sleep * $attempt,
                function (Throwable $e) use ($idempotent): bool {
                    if ($e instanceof ConnectionException) {
                        return $idempotent;
                    }

                    if ($e instanceof RequestException) {
                        $status = $e->response->status();

                        return $status === 429 || ($idempotent && $status >= 500);
                    }

                    return false;
                },
                throw: false,
            );
    }

    protected function exception(Response $response): ProviderException
    {
        $message = trim($this->errorMessage($response)) ?: "HTTP {$response->status()}";

        return new ProviderException("{$this->type()->label()}: {$message}", $this->type()->value, $response->status());
    }

    /**
     * Provider-safe hostname: lowercase letters, digits and dashes, max 63 chars.
     */
    protected function hostname(string $name): string
    {
        $host = trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($name)), '-');

        return substr($host !== '' ? $host : 'falak-server', 0, 63);
    }
}
