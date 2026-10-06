<?php

namespace Falak\Kernel\Support;

/**
 * Masks secret values in text, like the agent's redact package (agent/internal/redact): each value of at least
 * MIN_LENGTH bytes is matched as is, base64 (standard and URL alphabets, padding optional) and URL-encoded, and every
 * match (overlapping ones merged) becomes MASK. The agent already masks what it ships; this is the second pass for
 * the control plane's own log sinks. Secrets split across two stored chunks are the agent's job.
 */
final class SecretMask
{
    public const MASK = '••••';

    public const MIN_LENGTH = 6;

    /** @var list<string> longest first */
    private array $patterns = [];

    /**
     * @param  iterable<mixed>  $values
     */
    public function __construct(iterable $values = [])
    {
        $patterns = [];

        foreach ($values as $value) {
            $value = (string) $value;

            if (strlen($value) < self::MIN_LENGTH) {
                continue;
            }

            $base64 = rtrim(base64_encode($value), '=');

            foreach ([$value, $base64, strtr($base64, '+/', '-_'), urlencode($value), rawurlencode($value)] as $form) {
                $patterns[$form] = true;
            }
        }

        $this->patterns = array_map('strval', array_keys($patterns));
        usort($this->patterns, fn (string $a, string $b) => strlen($b) <=> strlen($a) ?: strcmp($a, $b));
    }

    public function isEmpty(): bool
    {
        return $this->patterns === [];
    }

    public function apply(string $text): string
    {
        if ($this->patterns === [] || $text === '') {
            return $text;
        }

        $spans = [];

        foreach ($this->patterns as $pattern) {
            for ($offset = 0; ($at = strpos($text, $pattern, $offset)) !== false; $offset = $at + 1) {
                $spans[] = [$at, $at + strlen($pattern)];
            }
        }

        if ($spans === []) {
            return $text;
        }

        usort($spans, fn (array $a, array $b) => $a[0] <=> $b[0]);
        $out = '';
        $at = 0;
        [$start, $end] = $spans[0];

        foreach ($spans as [$s, $e]) {
            if ($s <= $end) {
                $end = max($end, $e);

                continue;
            }

            $out .= substr($text, $at, $start - $at).self::MASK;
            $at = $end;
            [$start, $end] = [$s, $e];
        }

        return $out.substr($text, $at, $start - $at).self::MASK.substr($text, $end);
    }
}
