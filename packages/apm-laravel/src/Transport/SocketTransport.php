<?php

namespace Falak\Apm\Transport;

use Throwable;

/**
 * Minimal HTTP/1.1 client over stream_socket_client for unix sockets and TCP.
 *
 * Tries each endpoint in order (unix socket first, then the TCP fallback) and gives up
 * silently. Each endpoint gets its own `timeout` budget covering connect, write and read.
 */
final class SocketTransport implements Transport
{
    /** @var list<array{0: string, 1: string, 2: string}> [socket uri, path prefix, host header] */
    private array $endpoints = [];

    /** @param list<string|null> $endpoints */
    public function __construct(array $endpoints, private float $timeout = 0.25, private string $userAgent = 'falak-apm-laravel')
    {
        foreach ($endpoints as $endpoint) {
            if (is_string($endpoint) && $endpoint !== '' && ($parsed = self::parse($endpoint)) !== null) {
                $this->endpoints[] = $parsed;
            }
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string}|null
     */
    public static function parse(string $endpoint): ?array
    {
        if (str_starts_with($endpoint, 'unix:')) {
            $path = substr($endpoint, 5);
            $path = str_starts_with($path, '//') ? substr($path, 2) : $path;

            return $path === '' ? null : ['unix://'.$path, '', 'localhost'];
        }

        $parts = parse_url($endpoint);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        $scheme = $parts['scheme'] ?? 'http';
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $transport = $scheme === 'https' ? 'tls' : 'tcp';

        return [
            "{$transport}://{$parts['host']}:{$port}",
            rtrim($parts['path'] ?? '', '/'),
            $parts['host'].':'.$port,
        ];
    }

    public function send(string $path, string $body): bool
    {
        foreach ($this->endpoints as [$uri, $prefix, $host]) {
            try {
                $result = $this->sendTo($uri, $prefix.$path, $host, $body);
            } catch (Throwable) {
                $result = null;
            }

            // null = could not connect: try the next endpoint. bool = delivered (or rejected).
            if ($result !== null) {
                return $result;
            }
        }

        return false;
    }

    private function sendTo(string $uri, string $path, string $host, string $body): ?bool
    {
        $deadline = microtime(true) + $this->timeout;

        $socket = @stream_socket_client($uri, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT);

        if ($socket === false) {
            return null;
        }

        try {
            $request = "POST {$path} HTTP/1.1\r\n"
                ."Host: {$host}\r\n"
                ."User-Agent: {$this->userAgent}\r\n"
                ."Content-Type: application/json\r\n"
                .'Content-Length: '.strlen($body)."\r\n"
                ."Connection: close\r\n\r\n"
                .$body;

            stream_set_blocking($socket, false);

            $written = 0;
            $length = strlen($request);

            while ($written < $length) {
                $remaining = $deadline - microtime(true);

                if ($remaining <= 0) {
                    return false;
                }

                $read = null;
                $write = [$socket];
                $except = null;

                if (@stream_select($read, $write, $except, 0, (int) ($remaining * 1_000_000)) < 1) {
                    return false;
                }

                $n = @fwrite($socket, $written === 0 ? $request : substr($request, $written));

                if ($n === false) {
                    return false;
                }

                $written += $n;
            }

            // Read just the status line so the agent sees an orderly exchange.
            $line = '';

            while (! str_contains($line, "\n")) {
                $remaining = $deadline - microtime(true);

                if ($remaining <= 0) {
                    break;
                }

                $read = [$socket];
                $write = null;
                $except = null;

                if (@stream_select($read, $write, $except, 0, (int) ($remaining * 1_000_000)) < 1) {
                    break;
                }

                $chunk = @fread($socket, 256);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $line .= $chunk;
            }

            // Delivered even if the status line did not arrive in time.
            return $line === '' || (bool) preg_match('#^HTTP/\d(?:\.\d)? 2\d\d#', $line);
        } finally {
            @fclose($socket);
        }
    }
}
