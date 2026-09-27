<?php

namespace Kiln\Kernel\Support;

use Closure;
use Illuminate\Http\Request;

/**
 * Registry of Inertia props shared with every page. Modules register lazy resolvers from their
 * service provider (`$props->register('kiln', fn (Request $r) => ...)`); the app's Inertia middleware
 * merges {@see for()} into its shared props, so app glue never imports module internals.
 */
final class SharedProps
{
    /** @var array<string, array{resolver: Closure(Request): mixed, authenticated: bool}> */
    private array $props = [];

    /**
     * @param  Closure(Request): mixed  $resolver  evaluated lazily, once per response that includes the prop
     * @param  bool  $authenticated  only share the prop when a user is signed in
     */
    public function register(string $key, Closure $resolver, bool $authenticated = true): void
    {
        $this->props[$key] = ['resolver' => $resolver, 'authenticated' => $authenticated];
    }

    public function has(string $key): bool
    {
        return isset($this->props[$key]);
    }

    /**
     * Lazy props for the request, keyed by prop name.
     *
     * @return array<string, Closure(): mixed>
     */
    public function for(Request $request): array
    {
        $shared = [];

        foreach ($this->props as $key => $prop) {
            if ($prop['authenticated'] && $request->user() === null) {
                continue;
            }

            $shared[$key] = fn () => ($prop['resolver'])($request);
        }

        return $shared;
    }
}
