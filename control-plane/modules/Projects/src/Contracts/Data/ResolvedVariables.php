<?php

namespace Falak\Projects\Contracts\Data;

/**
 * Result of resolving `${{ service.KEY }}` references in a site's variables. Contains secrets
 * (resolved database passwords): never log it or send it to the UI.
 */
final readonly class ResolvedVariables
{
    /**
     * @param  array<string, string>  $variables  input variables with every resolvable reference substituted
     * @param  list<string>  $errors  one human-readable message per unresolved reference / cycle
     * @param  list<array{service: string, key: string}>  $references  every reference found (resolved or not)
     */
    public function __construct(
        public array $variables,
        public array $errors,
        public array $references,
    ) {}

    public function ok(): bool
    {
        return $this->errors === [];
    }

    /** One-line summary of the errors, e.g. for a failed deployment. */
    public function errorSummary(): string
    {
        return 'Unresolved variable references: '.implode(' ', $this->errors);
    }
}
