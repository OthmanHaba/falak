<?php

namespace Falak\Apm\Watchers;

use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Factory;
use Falak\Apm\Recorder;
use Falak\Apm\Span;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Registered as a global Guzzle middleware on Laravel's HTTP client: times each request,
 * records an outgoing_request span and propagates W3C `traceparent`.
 */
final class HttpClientWatcher
{
    public function __construct(private Recorder $recorder)
    {
    }

    public function register(Factory $factory): void
    {
        $factory->globalMiddleware($this->middleware());
    }

    public function middleware(): callable
    {
        $recorder = $this->recorder;

        return static fn (callable $handler) => static function (RequestInterface $request, array $options) use ($handler, $recorder) {
            if (! $recorder->recording('outgoing_request') || ($ctx = $recorder->context()) === null) {
                return $handler($request, $options);
            }

            $spanId = Recorder::spanId();
            $start = $recorder->now();
            $request = $request->withHeader('traceparent', '00-'.$ctx->traceId.'-'.$spanId.'-01');

            $attributes = [
                'http.request.method' => $request->getMethod(),
                'url.full' => (string) $request->getUri(),
                'server.address' => $request->getUri()->getHost(),
            ];

            if (($port = $request->getUri()->getPort()) !== null) {
                $attributes['server.port'] = $port;
            }

            $name = $request->getMethod().' '.$request->getUri()->getHost();

            return $handler($request, $options)->then(
                static function (ResponseInterface $response) use ($recorder, $attributes, $start, $spanId, $name) {
                    try {
                        $status = $response->getStatusCode();
                        $attributes['http.response.status_code'] = $status;

                        if ($status >= 400) {
                            $attributes['error.type'] = (string) $status;
                        }

                        $recorder->record('outgoing_request', $name, Span::KIND_CLIENT, $start, $recorder->now(), $attributes,
                            $status >= 400 ? Span::STATUS_ERROR : Span::STATUS_UNSET, $spanId);
                    } catch (Throwable) {
                    }

                    return $response;
                },
                static function ($reason) use ($recorder, $attributes, $start, $spanId, $name) {
                    try {
                        $attributes['error.type'] = is_object($reason) ? get_class($reason) : 'error';

                        $recorder->record('outgoing_request', $name, Span::KIND_CLIENT, $start, $recorder->now(), $attributes,
                            Span::STATUS_ERROR, $spanId, $reason instanceof Throwable ? $reason->getMessage() : null);
                    } catch (Throwable) {
                    }

                    return Create::rejectionFor($reason);
                },
            );
        };
    }
}
