<?php

namespace Kiln\Templates\Application\Compose;

use Kiln\Templates\Domain\InvalidTemplate;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * A parsed compose file with the lookups templates need: images, container ports, Compose interpolation
 * (`${VAR}`) and Kiln placeholders (`${{ kiln.url(svc) }}`). Only string *values* are scanned — comments are
 * not interpolated by Compose either.
 */
final readonly class ComposeDocument
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(public array $data) {}

    /**
     * @throws InvalidTemplate
     */
    public static function parse(string $yaml): self
    {
        try {
            $data = Yaml::parse($yaml);
        } catch (ParseException $e) {
            throw new InvalidTemplate(['compose.yaml: '.$e->getMessage()]);
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidTemplate(['compose.yaml: must be a mapping with `services:`']);
        }

        if (! is_array($data['services'] ?? null) || $data['services'] === [] || array_is_list($data['services'])) {
            throw new InvalidTemplate(['compose.yaml: `services:` must map at least one service']);
        }

        foreach ($data['services'] as $name => $service) {
            if (! is_array($service)) {
                throw new InvalidTemplate(["compose.yaml: services.{$name} must be a mapping"]);
            }
        }

        /** @var array<string, mixed> $data */
        return new self($data);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function services(): array
    {
        /** @var array<string, array<string, mixed>> */
        return $this->data['services'];
    }

    /**
     * @return list<string>
     */
    public function serviceNames(): array
    {
        return array_map('strval', array_keys($this->services()));
    }

    public function service(string $name): ?array
    {
        return $this->services()[$name] ?? null;
    }

    /**
     * Container-side ports a service declares (`ports:` short / long syntax and `expose:`).
     *
     * @return list<int>
     */
    public function containerPorts(string $service): array
    {
        $definition = $this->service($service) ?? [];
        $ports = [];

        foreach ((array) ($definition['expose'] ?? []) as $entry) {
            $ports = [...$ports, ...self::portRange((string) $entry)];
        }

        foreach ((array) ($definition['ports'] ?? []) as $entry) {
            if (is_array($entry)) {
                if (isset($entry['target'])) {
                    $ports = [...$ports, ...self::portRange((string) $entry['target'])];
                }

                continue;
            }

            $parts = explode(':', (string) $entry);
            $ports = [...$ports, ...self::portRange((string) end($parts))];
        }

        return array_values(array_unique($ports));
    }

    /**
     * Compose interpolation references in string values: `${VAR}`, `${VAR:-default}`, `$VAR` (not `$$`).
     *
     * @return list<array{name: string, optional: bool}>
     */
    public function variables(): array
    {
        $found = [];

        foreach ($this->strings() as $value) {
            foreach (self::scan($value) as $reference) {
                $found[$reference['name']] = [
                    'name' => $reference['name'],
                    'optional' => ($found[$reference['name']]['optional'] ?? true) && $reference['optional'],
                ];
            }
        }

        return array_values($found);
    }

    /**
     * `${{ … }}` placeholders in string values (their inner expression, trimmed).
     *
     * @return list<string>
     */
    public function placeholders(): array
    {
        $found = [];

        foreach ($this->strings() as $value) {
            if (preg_match_all('/(?<!\$)\$\{\{(.*?)\}\}/s', $value, $matches) > 0) {
                foreach ($matches[1] as $expression) {
                    $found[] = trim($expression);
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @return list<string> every string value (and mapping key) in the document
     */
    public function strings(): array
    {
        $out = [];
        $walk = function (mixed $node) use (&$walk, &$out): void {
            if (is_string($node)) {
                $out[] = $node;
            } elseif (is_array($node)) {
                foreach ($node as $key => $child) {
                    if (is_string($key)) {
                        $out[] = $key;
                    }
                    $walk($child);
                }
            }
        };
        $walk($this->data);

        return $out;
    }

    /**
     * @return list<array{name: string, optional: bool}>
     */
    public static function scan(string $value): array
    {
        $found = [];
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            if ($value[$i] !== '$') {
                continue;
            }

            $next = $value[$i + 1] ?? '';

            if ($next === '$') {
                $i++; // escaped literal $

                continue;
            }

            if ($next === '{' && ($value[$i + 2] ?? '') === '{') {
                $end = strpos($value, '}}', $i);
                $i = $end === false ? $length : $end + 1; // Kiln placeholder, not Compose

                continue;
            }

            if ($next === '{') {
                if (preg_match('/\G\$\{([A-Za-z_][A-Za-z0-9_]*)(?:(:?[-?+])((?:[^{}]|\{[^{}]*\})*))?\}/', $value, $m, 0, $i) === 1) {
                    $modifier = $m[2] ?? '';
                    $found[] = ['name' => $m[1], 'optional' => $modifier !== '' && $modifier[strlen($modifier) - 1] !== '?'];

                    foreach (self::scan($m[3] ?? '') as $nested) {
                        $found[] = $nested;
                    }

                    $i += strlen($m[0]) - 1;
                }

                continue;
            }

            if (preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)/', $value, $m, 0, $i) === 1) {
                $found[] = ['name' => $m[1], 'optional' => false];
                $i += strlen($m[0]) - 1;
            }
        }

        return $found;
    }

    /**
     * @return list<int>
     */
    private static function portRange(string $spec): array
    {
        $spec = trim(explode('/', $spec)[0]);

        if (preg_match('/^(\d{1,5})(?:-(\d{1,5}))?$/', $spec, $m) !== 1) {
            return [];
        }

        $from = (int) $m[1];
        $to = isset($m[2]) ? (int) $m[2] : $from;

        return $to >= $from && $to - $from <= 100 ? range($from, $to) : [$from];
    }
}
