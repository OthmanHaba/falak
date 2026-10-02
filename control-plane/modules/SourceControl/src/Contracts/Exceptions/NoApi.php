<?php

namespace Kiln\SourceControl\Contracts\Exceptions;

/**
 * The connection's git server has no provider API (custom git): files can't be read without a clone.
 */
class NoApi extends SourceControlException
{
    public static function forConnection(string $name): self
    {
        return new self("{$name} is a plain git server: Kiln can't read files from it without cloning.");
    }
}
