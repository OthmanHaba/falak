<?php

namespace Kiln\Terminal\Infrastructure;

use Kiln\Terminal\Domain\Models\TerminalFrame;
use Kiln\Terminal\Domain\Models\TerminalSession;

/**
 * Renders a session recording as asciicast v2 (https://docs.asciinema.org/manual/asciicast/v2/):
 * a JSON header line followed by one `[seconds, "o"|"r", data]` JSON line per event.
 *
 * PTY output is raw bytes split at arbitrary points, so a multibyte UTF-8 character may straddle two
 * frames. An incomplete trailing sequence is carried into the next output frame; bytes that can never
 * form valid UTF-8 become U+FFFD. Event times never decrease.
 */
final class AsciicastWriter
{
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /**
     * @param  iterable<TerminalFrame>|null  $frames  defaults to the session's frames (ordered)
     */
    public function write(TerminalSession $session, ?iterable $frames = null): string
    {
        $out = json_encode($this->header($session), self::FLAGS)."\n";

        foreach ($this->events($frames ?? $session->frames()->cursor()) as $event) {
            $out .= json_encode($event, self::FLAGS)."\n";
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function header(TerminalSession $session): array
    {
        return [
            'version' => 2,
            'width' => $session->initial_cols,
            'height' => $session->initial_rows,
            'timestamp' => ($session->started_at ?? $session->created_at)->getTimestamp(),
            'env' => ['TERM' => (string) config('terminal.term', 'xterm-256color'), 'SHELL' => (string) config('terminal.shell', '/bin/bash')],
            'title' => "{$session->unix_user}@{$session->server_name}",
        ];
    }

    /**
     * @param  iterable<TerminalFrame>  $frames
     * @return \Generator<int, array{0: float, 1: string, 2: string}>
     */
    public function events(iterable $frames): \Generator
    {
        $carry = '';
        $time = 0.0;

        foreach ($frames as $frame) {
            $time = max($time, round($frame->offset_ms / 1000, 3));

            if ($frame->kind === TerminalFrame::RESIZE) {
                yield [$time, 'r', $frame->data];

                continue;
            }

            $raw = base64_decode($frame->data, true);

            if ($raw === false) {
                continue;
            }

            [$text, $carry] = self::decode($carry.$raw);

            if ($text !== '') {
                yield [$time, 'o', $text];
            }
        }

        if ($carry !== '') {
            yield [$time, 'o', self::scrub($carry)];
        }
    }

    /**
     * Split bytes into valid UTF-8 text and an incomplete trailing sequence to prepend to the next chunk.
     *
     * @return array{0: string, 1: string}
     */
    public static function decode(string $bytes): array
    {
        $length = strlen($bytes);
        $carryFrom = $length;

        // Look back at most 3 bytes for the lead byte of an unfinished sequence.
        for ($i = $length - 1; $i >= max(0, $length - 3); $i--) {
            $byte = ord($bytes[$i]);

            if (($byte & 0xC0) === 0x80) {
                continue; // continuation byte
            }

            $needed = match (true) {
                ($byte & 0xE0) === 0xC0 => 2,
                ($byte & 0xF0) === 0xE0 => 3,
                ($byte & 0xF8) === 0xF0 => 4,
                default => 1,
            };

            if ($needed > $length - $i) {
                $carryFrom = $i;
            }

            break;
        }

        return [self::scrub(substr($bytes, 0, $carryFrom)), substr($bytes, $carryFrom)];
    }

    /** Replace invalid UTF-8 byte sequences with U+FFFD. */
    public static function scrub(string $bytes): string
    {
        if ($bytes === '' || mb_check_encoding($bytes, 'UTF-8')) {
            return $bytes;
        }

        $previous = mb_substitute_character();
        mb_substitute_character(0xFFFD);

        try {
            return mb_scrub($bytes, 'UTF-8');
        } finally {
            mb_substitute_character($previous);
        }
    }
}
