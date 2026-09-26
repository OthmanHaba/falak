<?php

namespace Kiln\Deployments\Application\Planning;

use InvalidArgumentException;

/**
 * Splits a deploy script at its macro lines ($KILN_FETCH, $KILN_ACTIVATE, $KILN_RESTART_PROCS):
 *
 *     <before_fetch>  $KILN_FETCH  <before_activate>  $KILN_ACTIVATE  <after_activate>  $KILN_RESTART_PROCS  <after_restart>
 *
 * A missing $KILN_FETCH means the release is fetched first; a missing $KILN_ACTIVATE means it is
 * activated after the script; a missing $KILN_RESTART_PROCS means processes restart right after
 * activation. Macros used inside other lines stay no-ops (the agent defines them as ':').
 */
final readonly class ScriptSections
{
    public const MACROS = ['KILN_FETCH', 'KILN_ACTIVATE', 'KILN_RESTART_PROCS'];

    public function __construct(
        public string $beforeFetch,
        public string $beforeActivate,
        public string $afterActivate,
        public string $afterRestart,
    ) {}

    /**
     * @throws InvalidArgumentException when macros repeat or are out of order
     */
    public static function parse(string $script): self
    {
        $buckets = ['KILN_FETCH' => [], 'KILN_ACTIVATE' => [], 'KILN_RESTART_PROCS' => [], 'end' => []];
        $seen = [];
        $current = [];
        $lines = preg_split('/\r?\n/', $script) ?: [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*\$\{?(KILN_FETCH|KILN_ACTIVATE|KILN_RESTART_PROCS)\}?\s*(;\s*)?(#.*)?$/', $line, $m) === 1) {
                $macro = $m[1];

                if (isset($seen[$macro])) {
                    throw new InvalidArgumentException("\${$macro} is used more than once in the deploy script.");
                }

                $position = array_search($macro, self::MACROS, true);

                foreach (array_keys($seen) as $earlier) {
                    if (array_search($earlier, self::MACROS, true) > $position) {
                        throw new InvalidArgumentException("\${$macro} must come before \${$earlier} in the deploy script.");
                    }
                }

                $buckets[$macro] = $current;
                $current = [];
                $seen[$macro] = true;

                continue;
            }

            $current[] = $line;
        }

        $buckets['end'] = $current;

        // Lines before a macro belong to the section preceding it; the tail belongs after the last one.
        $sections = ['before_fetch' => [], 'before_activate' => [], 'after_activate' => [], 'after_restart' => []];
        $sections['before_fetch'] = isset($seen['KILN_FETCH']) ? $buckets['KILN_FETCH'] : [];
        $preFetchCarry = isset($seen['KILN_FETCH']) ? [] : $buckets['KILN_FETCH'];

        $sections['before_activate'] = array_merge($preFetchCarry, $buckets['KILN_ACTIVATE']);
        $sections['after_activate'] = $buckets['KILN_RESTART_PROCS'];

        if (! isset($seen['KILN_ACTIVATE'])) {
            // Everything runs before activation.
            $sections['before_activate'] = array_merge($sections['before_activate'], $sections['after_activate'], $buckets['end']);
            $sections['after_activate'] = [];
            $sections['after_restart'] = [];
        } elseif (! isset($seen['KILN_RESTART_PROCS'])) {
            $sections['after_activate'] = array_merge($sections['after_activate'], $buckets['end']);
        } else {
            $sections['after_restart'] = $buckets['end'];
        }

        return new self(
            self::render($sections['before_fetch']),
            self::render($sections['before_activate']),
            self::render($sections['after_activate']),
            self::render($sections['after_restart']),
        );
    }

    /**
     * @param  list<string>  $lines
     */
    private static function render(array $lines): string
    {
        $meaningful = array_filter($lines, fn (string $line) => trim($line) !== '' && ! str_starts_with(trim($line), '#'));

        return $meaningful === [] ? '' : trim(implode("\n", $lines))."\n";
    }
}
