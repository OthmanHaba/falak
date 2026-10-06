<?php

namespace Falak\Secrets\Contracts\Data;

/**
 * Secrets resolved for a scope chain. Contains secret values: never log it or send it to the UI.
 */
final readonly class ResolvedSecrets
{
    /**
     * @param  array<string, string>  $values  name => value (only the names that resolved)
     * @param  list<string>  $sensitive  resolved names whose secret is marked sensitive (write-only)
     * @param  array<string, string>  $errors  name => why it did not resolve (not defined, disabled, provider failure)
     */
    public function __construct(
        public array $values,
        public array $sensitive,
        public array $errors,
    ) {}

    public function ok(): bool
    {
        return $this->errors === [];
    }

    public function isSensitive(string $name): bool
    {
        return in_array($name, $this->sensitive, true);
    }
}
