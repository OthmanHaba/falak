<?php

namespace Falak\Secrets\Infrastructure;

use Falak\Kernel\Security\DecryptionFailed;
use Falak\Kernel\Security\KeyUnavailable;
use Falak\Secrets\Application\AccessRecorder;
use Falak\Secrets\Application\SecretCipher;
use Falak\Secrets\Application\SecretLookup;
use Falak\Secrets\Contracts\Data\ResolvedSecrets;
use Falak\Secrets\Contracts\Data\ScopeChain;
use Falak\Secrets\Contracts\Data\SecretAccessor;
use Falak\Secrets\Contracts\Exceptions\SecretProviderUnavailable;
use Falak\Secrets\Contracts\SecretProviders;
use Falak\Secrets\Contracts\Secrets;
use Falak\Secrets\Domain\Enums\SecretKind;
use Falak\Secrets\Domain\Models\Secret;
use Falak\Secrets\Domain\Models\SecretVersion;

/**
 * Request-scoped (the accessor stack of {@see accessedAs()} never leaks into the next request or job).
 */
final class SecretStore implements Secrets
{
    /** @var list<SecretAccessor> */
    private array $accessors = [];

    public function __construct(
        private readonly SecretLookup $lookup,
        private readonly SecretCipher $cipher,
        private readonly SecretProviders $providers,
        private readonly AccessRecorder $recorder,
    ) {}

    public function resolve(ScopeChain $chain, array $names, ?SecretAccessor $accessor = null, bool $forPreview = false): ResolvedSecrets
    {
        return $this->run($chain, $names, $accessor ?? $this->accessor(), $forPreview);
    }

    public function check(ScopeChain $chain, array $names, bool $forPreview = false): ResolvedSecrets
    {
        return $this->run($chain, $names, null, $forPreview);
    }

    public function accessedAs(SecretAccessor $accessor, callable $callback): mixed
    {
        $this->accessors[] = $accessor;

        try {
            return $callback();
        } finally {
            array_pop($this->accessors);
        }
    }

    /**
     * @param  list<string>  $names
     * @param  SecretAccessor|null  $accessor  null: check only (read nothing)
     */
    private function run(ScopeChain $chain, array $names, ?SecretAccessor $accessor, bool $forPreview): ResolvedSecrets
    {
        $names = array_values(array_unique($names));
        $secrets = $this->lookup->nearest($chain, $names);
        $versions = SecretVersion::query()
            ->whereIn('secret_id', array_map(fn (Secret $secret) => $secret->id, array_values($secrets)))
            ->get()
            ->keyBy(fn (SecretVersion $version) => "{$version->secret_id}.{$version->version}");

        $values = [];
        $sensitive = [];
        $errors = [];

        foreach ($names as $name) {
            $secret = $secrets[$name] ?? null;

            if ($secret === null) {
                $errors[$name] = "secret {$name} is not defined for this service (in its service, environment, project or organization secrets)";

                continue;
            }

            // The nearest secret decides: a farther one is not a fallback for a secret kept from previews.
            if ($forPreview && ! $secret->available_to_previews) {
                $errors[$name] = "secret {$name} is not available to preview environments (turn it on in the secret's settings)";

                continue;
            }

            $version = $versions["{$secret->id}.{$secret->current_version}"] ?? null;

            if ($version === null) {
                $errors[$name] = "secret {$name} has no value";

                continue;
            }

            if ($version->disabled_at !== null) {
                $errors[$name] = "secret {$name}: its current version (v{$version->version}) is disabled; set a new value or roll back";

                continue;
            }

            if ($secret->sensitive) {
                $sensitive[] = $name;
            }

            if ($accessor === null) {
                continue;
            }

            try {
                $value = $this->cipher->open($secret, $version);

                if ($secret->kind === SecretKind::Linked) {
                    $value = $this->providers->resolve($value, $secret->provider_id, $secret->organization_id);
                }
            } catch (SecretProviderUnavailable $e) {
                $errors[$name] = "secret {$name}: {$e->getMessage()}";

                continue;
            } catch (DecryptionFailed|KeyUnavailable) {
                // Never the cause: it may describe key material.
                $errors[$name] = "secret {$name} could not be decrypted (is the key-encryption key available?)";

                continue;
            }

            $values[$name] = $value;
            $this->recorder->record($secret, $version->version, $accessor);
        }

        return new ResolvedSecrets($values, $sensitive, $errors);
    }

    /** The accessor of the innermost accessedAs(), else the signed-in user, else the system. */
    private function accessor(): SecretAccessor
    {
        if ($this->accessors !== []) {
            return $this->accessors[array_key_last($this->accessors)];
        }

        $request = app()->bound('request') ? request() : null;
        $user = $request?->user();

        return $user !== null
            ? SecretAccessor::user((string) $user->getAuthIdentifier(), 'Variables resolved', $request->ip())
            : SecretAccessor::system('Variables resolved');
    }
}
