<?php

namespace Kiln\Insights\Domain\Support;

/**
 * Groups exceptions: normalized type + the top N in-app frames (file + function, no line numbers,
 * release paths stripped). Falls back to the top frames, then to the normalized message.
 */
final class Fingerprinter
{
    public function __construct(private readonly int $frames = 3) {}

    public function fingerprint(string $type, ?string $message, ?string $stacktrace): Fingerprint
    {
        $type = self::normalizeType($type);
        $frames = StackTrace::parse($stacktrace);
        $inApp = array_values(array_filter($frames, fn (StackFrame $frame) => $frame->inApp));
        $significant = array_slice($inApp !== [] ? $inApp : array_values(array_filter($frames, fn (StackFrame $f) => $f->file !== null)), 0, $this->frames);

        $parts = [$type];

        if ($significant !== []) {
            foreach ($significant as $frame) {
                $parts[] = $frame->identity();
            }
        } else {
            $parts[] = self::normalizeMessage((string) $message);
        }

        $top = $inApp[0] ?? $significant[0] ?? null;
        $culprit = $top ? trim(($top->function ?? '').' ('.$top->file.($top->line ? ':'.$top->line : '').')') : null;

        return new Fingerprint(hash('sha256', implode("\n", $parts)), $type, $culprit, $frames);
    }

    public static function normalizeType(string $type): string
    {
        $type = ltrim(trim($type), '\\');

        return $type === '' ? 'Error' : $type;
    }

    /** Replace volatile tokens (ids, numbers, quoted values) so similar messages group together. */
    public static function normalizeMessage(string $message): string
    {
        $message = mb_strtolower(trim($message));
        $message = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', '<uuid>', $message) ?? $message;
        $message = preg_replace('/\b[0-9a-hjkmnp-tv-z]{26}\b/', '<ulid>', $message) ?? $message;
        $message = preg_replace('/\b0x[0-9a-f]+\b|\b[0-9a-f]{16,}\b/', '<hex>', $message) ?? $message;
        $message = preg_replace('/(["\'`]).*?\1/', '<str>', $message) ?? $message;
        $message = preg_replace('/\d+(\.\d+)?/', '<n>', $message) ?? $message;

        return mb_substr($message, 0, 500);
    }
}
