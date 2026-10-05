<?php

namespace Falak\Templates\Domain;

use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/**
 * `generate:` functions of template inputs. Values come from the CSPRNG (random_bytes / random_int) and are
 * generated once, when the site is created; they are stored as (encrypted) site variables afterwards.
 *
 *   secret(n)    n characters from [A-Za-z0-9]
 *   password(n)  n characters from an unambiguous alphabet with upper, lower and digits (URL / dotenv safe)
 *   uuid         random UUID v4
 *   hex(n)       n lowercase hex characters
 */
final readonly class Generator
{
    public const MIN_LENGTH = 8;

    public const MAX_LENGTH = 128;

    private const ALNUM = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    private const UNAMBIGUOUS_UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const UNAMBIGUOUS_LOWER = 'abcdefghijkmnopqrstuvwxyz';

    private const UNAMBIGUOUS_DIGITS = '23456789';

    private function __construct(public string $function, public ?int $length) {}

    /**
     * @throws InvalidArgumentException
     */
    public static function parse(string $spec): self
    {
        $spec = trim($spec);

        if ($spec === 'uuid' || $spec === 'uuid()') {
            return new self('uuid', null);
        }

        if (preg_match('/^(secret|password|hex)\(\s*(\d{1,4})\s*\)$/', $spec, $m) !== 1) {
            throw new InvalidArgumentException('must be secret(n), password(n), hex(n) or uuid');
        }

        $length = (int) $m[2];

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf('length must be between %d and %d', self::MIN_LENGTH, self::MAX_LENGTH));
        }

        return new self($m[1], $length);
    }

    public function generate(): string
    {
        return match ($this->function) {
            'uuid' => Uuid::uuid4()->toString(),
            'hex' => substr(bin2hex(random_bytes(intdiv((int) $this->length + 1, 2))), 0, (int) $this->length),
            'password' => $this->password((int) $this->length),
            default => self::pick(self::ALNUM, (int) $this->length),
        };
    }

    public function __toString(): string
    {
        return $this->length === null ? $this->function : "{$this->function}({$this->length})";
    }

    private function password(int $length): string
    {
        // One of each class, the rest from the whole alphabet, then a CSPRNG shuffle (Fisher–Yates).
        $all = self::UNAMBIGUOUS_UPPER.self::UNAMBIGUOUS_LOWER.self::UNAMBIGUOUS_DIGITS;
        $chars = str_split(self::pick(self::UNAMBIGUOUS_UPPER, 1).self::pick(self::UNAMBIGUOUS_LOWER, 1).self::pick(self::UNAMBIGUOUS_DIGITS, 1).self::pick($all, $length - 3));

        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    private static function pick(string $alphabet, int $length): string
    {
        $max = strlen($alphabet) - 1;
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }
}
