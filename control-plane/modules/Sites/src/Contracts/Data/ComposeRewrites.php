<?php

namespace Kiln\Sites\Contracts\Data;

/**
 * Variables of a compose stack that pointed at services now running as Kiln services, and what they become — per
 * group: each remaining service's `environment:` and the stack's own variables ({@see STACK}). Two services may use
 * the same name (DB_PASSWORD) for different databases, so a service's rewrite gets its own project `.env` name
 * ({@see variable()}); the renderer points the service's key at it (`DB_PASSWORD: ${KILN_SVC_WORKER_DB_PASSWORD}`).
 */
final readonly class ComposeRewrites
{
    /** Group of the stack's own variables (compose service names never start with a dot). */
    public const STACK = '.stack';

    /**
     * @param  array<string, array<string, string>>  $groups  service (or STACK) => variable => replacement
     */
    public function __construct(public array $groups = []) {}

    /** @return array<string, string> */
    public function forService(string $service): array
    {
        return $this->groups[$service] ?? [];
    }

    /** @return array<string, string> */
    public function stack(): array
    {
        return $this->groups[self::STACK] ?? [];
    }

    public function isEmpty(): bool
    {
        return array_filter($this->groups) === [];
    }

    /** The project `.env` variable a service's rewritten key reads. */
    public static function variable(string $service, string $key): string
    {
        return 'KILN_SVC_'.strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', $service)).'_'.$key;
    }

    /**
     * Everything the stack's environment gains: the stack's own rewritten variables (replacing them) and one variable
     * per service rewrite ({@see variable()}). Values may contain `${{ service.KEY }}` references.
     *
     * @return array<string, string>
     */
    public function dotenv(): array
    {
        $out = $this->stack();

        foreach ($this->groups as $service => $variables) {
            if ($service === self::STACK) {
                continue;
            }

            foreach ($variables as $key => $replacement) {
                $out[self::variable($service, $key)] = $replacement;
            }
        }

        ksort($out);

        return $out;
    }
}
