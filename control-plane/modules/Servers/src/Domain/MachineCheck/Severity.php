<?php

namespace Kiln\Servers\Domain\MachineCheck;

enum Severity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Block = 'block';

    public function rank(): int
    {
        return match ($this) {
            self::Info => 0,
            self::Warning => 1,
            self::Block => 2,
        };
    }

    public static function max(self ...$severities): self
    {
        $max = self::Info;

        foreach ($severities as $severity) {
            if ($severity->rank() > $max->rank()) {
                $max = $severity;
            }
        }

        return $max;
    }
}
