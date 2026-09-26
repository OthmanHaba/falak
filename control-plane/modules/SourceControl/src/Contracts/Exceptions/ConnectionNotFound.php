<?php

namespace Kiln\SourceControl\Contracts\Exceptions;

final class ConnectionNotFound extends SourceControlException
{
    public static function id(string $id): self
    {
        return new self("Source control connection {$id} not found.");
    }
}
