<?php

namespace Falak\Projects\Contracts\Data;

/**
 * Result of resolving `${{ service.KEY }}` and `${{ secrets.NAME }}` references in a site's variables. Contains
 * secrets (resolved database passwords, secret store values): never log it or send it to the UI.
 */
final readonly class ResolvedVariables
{
    /**
     * @param  array<string, string>  $variables  input variables with every resolvable reference substituted
     * @param  list<string>  $errors  one human-readable message per unresolved reference / cycle
     * @param  list<array{service: string, key: string}>  $references  every reference found (resolved or not)
     * @param  list<string>  $secretKeys  variables whose value includes a secret store value (mask these in output)
     * @param  list<string>  $sensitiveKeys  the subset that includes a sensitive (write-only) secret
     */
    public function __construct(
        public array $variables,
        public array $errors,
        public array $references,
        public array $secretKeys = [],
        public array $sensitiveKeys = [],
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
