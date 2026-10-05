<?php

namespace Falak\Apm;

use Illuminate\Support\Str;
use Throwable;

/**
 * Applied at flush time (after the response is sent), never in the hot path.
 */
final class Redactor
{
    /** @var list<string> */
    private array $keys;

    private string $replacement;

    /** @var list<string> */
    private array $cacheKeyPatterns;

    private bool $queryLiterals;

    /** @var list<callable> */
    private array $callbacks = [];

    /** @param array<string, mixed> $config */
    public function __construct(array $config = [])
    {
        $this->keys = array_values(array_filter(array_map(
            fn ($k) => strtolower((string) $k),
            $config['keys'] ?? ['password', 'token', 'secret', 'authorization', 'cookie', 'api_key'],
        )));
        $this->replacement = (string) ($config['replacement'] ?? '[redacted]');
        $this->cacheKeyPatterns = array_values($config['cache_keys'] ?? []);
        $this->queryLiterals = (bool) ($config['query_literals'] ?? true);

        foreach ($config['callbacks'] ?? [] as $callback) {
            $this->callbacks[] = $callback;
        }
    }

    /** @param callable(array<string, mixed>, string): array<string, mixed> $callback */
    public function using(callable $callback): void
    {
        $this->callbacks[] = $callback;
    }

    public function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        foreach ($this->keys as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function span(Span $span): void
    {
        $type = $span->eventType() ?? '';

        foreach ($span->attributes as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            $span->attributes[$key] = match ($key) {
                'falak.event.type', 'db.query.text', 'falak.cache.key' => $value,
                'url.full' => $this->url($value),
                'url.query' => $this->queryString($value),
                default => $this->isSensitive($key) ? $this->replacement : $value,
            };
        }

        if ($this->queryLiterals && isset($span->attributes['db.query.text']) && is_string($span->attributes['db.query.text'])) {
            $span->attributes['db.query.text'] = $this->sql($span->attributes['db.query.text']);
        }

        if (isset($span->attributes['falak.cache.key']) && is_string($span->attributes['falak.cache.key'])) {
            $span->attributes['falak.cache.key'] = $this->cacheKey($span->attributes['falak.cache.key']);
        }

        foreach ($this->callbacks as $callback) {
            try {
                $result = $this->call($callback, $span->attributes, $type);

                if (is_array($result)) {
                    $span->attributes = $result;
                }
            } catch (Throwable) {
                // A broken user callback must never break the host application.
            }
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function context(array $context, int $depth = 0): array
    {
        foreach ($context as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $context[$key] = $this->replacement;
            } elseif (is_array($value) && $depth < 4) {
                $context[$key] = $this->context($value, $depth + 1);
            }
        }

        return $context;
    }

    public function sql(string $sql): string
    {
        // Bindings are never captured; also strip inline string literals.
        return preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", '?', $sql) ?? $sql;
    }

    public function cacheKey(string $key): string
    {
        if ($this->isSensitive($key)) {
            return $this->replacement;
        }

        foreach ($this->cacheKeyPatterns as $pattern) {
            if (Str::is($pattern, $key)) {
                return $this->replacement;
            }
        }

        return $key;
    }

    public function url(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return $url;
        }

        if (isset($parts['pass'])) {
            $url = str_replace(':'.$parts['pass'].'@', ':'.$this->replacement.'@', $url);
        }

        if (! isset($parts['query']) || $parts['query'] === '') {
            return $url;
        }

        $pos = strpos($url, '?');
        $hashPos = strpos($url, '#', (int) $pos);
        $fragment = $hashPos !== false ? substr($url, $hashPos) : '';

        return substr($url, 0, (int) $pos).'?'.$this->queryString($parts['query']).$fragment;
    }

    public function queryString(string $query): string
    {
        $pairs = explode('&', $query);

        foreach ($pairs as $i => $pair) {
            [$name] = explode('=', $pair, 2);

            if ($this->isSensitive(urldecode($name))) {
                $pairs[$i] = $name.'='.rawurlencode($this->replacement);
            }
        }

        return implode('&', $pairs);
    }

    /** @param array<string, mixed> $attributes */
    private function call(mixed $callback, array $attributes, string $type): mixed
    {
        if (is_string($callback) && str_contains($callback, '@')) {
            [$class, $method] = explode('@', $callback, 2);
            $callback = [app($class), $method];
        } elseif (is_array($callback) && is_string($callback[0] ?? null) && is_string($callback[1] ?? null)) {
            if (! method_exists($callback[0], $callback[1])) {
                return null;
            }

            if (! (new \ReflectionMethod($callback[0], $callback[1]))->isStatic()) {
                $callback = [app($callback[0]), $callback[1]];
            }
        } elseif (is_string($callback) && class_exists($callback)) {
            $callback = app($callback);
        }

        return is_callable($callback) ? $callback($attributes, $type) : null;
    }
}
