<?php

namespace Falak\Secrets\Contracts;

use Falak\Secrets\Contracts\Data\ResolvedSecrets;
use Falak\Secrets\Contracts\Data\ScopeChain;
use Falak\Secrets\Contracts\Data\SecretAccessor;

/**
 * The organization secret store, for other modules: `${{ secrets.NAME }}` in a site's variables resolves to the
 * current version of the nearest secret called NAME along the site's scope chain (service → environment →
 * project → organization). Every read is written to the secret access log with its accessor.
 */
interface Secrets
{
    /** Secret names are environment variable names. */
    public const NAME_PATTERN = '/^[A-Z_][A-Z0-9_]*$/';

    /**
     * Resolve the names (decrypting, or asking the provider of a linked secret) and log each read.
     *
     * @param  list<string>  $names
     * @param  SecretAccessor|null  $accessor  null: the one set by {@see accessedAs()}, else the signed-in user or the system
     */
    public function resolve(ScopeChain $chain, array $names, ?SecretAccessor $accessor = null): ResolvedSecrets;

    /**
     * Which names would resolve, without reading any value (no decryption, no provider call, nothing logged):
     * the result has no values, only the sensitive names and the errors.
     *
     * @param  list<string>  $names
     */
    public function check(ScopeChain $chain, array $names): ResolvedSecrets;

    /**
     * Run the callback with reads attributed to the accessor (e.g. a deployment building its step payloads).
     * A deployment logs one read per secret version, however many steps resolve it.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function accessedAs(SecretAccessor $accessor, callable $callback): mixed;
}
