<?php

namespace Falak\Kernel\Support;

/**
 * Masks secret values in text, like the agent's redact package (agent/internal/redact): each value of at least
 * MIN_LENGTH bytes is matched as is and in every form of forms(), and every match (overlapping ones merged) becomes
 * MASK. The agent already masks what it ships; this is the second pass for
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
    public function __construct(iterable $values = [], int $minLength = self::MIN_LENGTH)
    {
        $patterns = [];

        foreach ($values as $value) {
            $value = (string) $value;

            if (strlen($value) < $minLength) {
                continue;
            }

            $patterns[$value] = true;

            foreach (self::forms($value) as $form) {
                if (strlen($form) >= self::MIN_LENGTH) {
                    $patterns[$form] = true;
                }
            }
        }

        $this->patterns = array_map('strval', array_keys($patterns));
        usort($this->patterns, fn (string $a, string $b) => strlen($b) <=> strlen($a) ?: strcmp($a, $b));
    }

    /**
     * The forms a value leaks in, as the agent's redact package matches them: base64 (standard and URL alphabets) at
     * the three byte alignments it can have inside a longer encoding (only the characters that depend on the value
     * alone), URL-encoded, lower-case hex, JSON-escaped (PHP's json_encode and Go's, with and without HTML escaping)
     * and quoted inside a single-quoted shell word.
     *
     * @return list<string>
     */
    public static function forms(string $value): array
    {
        $forms = [];

        for ($shift = 0; $shift < 3; $shift++) {
            $full = base64_encode(str_repeat("\0", $shift).$value);
            $from = intdiv(8 * $shift + 5, 6);
            $to = intdiv(8 * ($shift + strlen($value)), 6);

            if ($to > $from) {
                $std = substr($full, $from, $to - $from);
                $forms[] = $std;
                $forms[] = strtr($std, '+/', '-_');
            }
        }

        $forms[] = urlencode($value);
        $forms[] = rawurlencode($value);
        $forms[] = bin2hex($value);

        foreach ([0, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE] as $flags) {
            $json = json_encode($value, $flags | JSON_INVALID_UTF8_SUBSTITUTE);

            if (is_string($json)) {
                // As written, and with lower-case hex unicode escapes like Go (JSON_HEX_TAG writes upper case).
                $forms[] = substr($json, 1, -1);
                $forms[] = preg_replace_callback('/\\\\u([0-9A-Fa-f]{4})/', fn (array $m) => '\\u'.strtolower($m[1]), substr($json, 1, -1));
            }
        }

        if (str_contains($value, "'")) {
            $forms[] = str_replace("'", "'\\''", $value);
        }

        return array_values(array_unique(array_filter($forms, fn ($form) => is_string($form) && $form !== '' && $form !== $value)));
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
