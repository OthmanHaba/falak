<?php

namespace Kiln\Terminal\Domain\Enums;

enum SessionStatus: string
{
    case Opening = 'opening';
    case Open = 'open';
    case Closed = 'closed';
    case Failed = 'failed';

    public function isLive(): bool
    {
        return $this === self::Opening || $this === self::Open;
    }

    /**
     * @return list<self>
     */
    public static function live(): array
    {
        return [self::Opening, self::Open];
    }
}
