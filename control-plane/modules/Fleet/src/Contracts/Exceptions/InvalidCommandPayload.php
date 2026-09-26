<?php

namespace Kiln\Fleet\Contracts\Exceptions;

use InvalidArgumentException;

final class InvalidCommandPayload extends InvalidArgumentException
{
    /**
     * @param  array<string, list<string>>  $errors  JSON pointer => messages
     */
    public function __construct(public readonly string $type, public readonly array $errors)
    {
        $summary = collect($errors)->map(fn (array $messages, string $path) => ($path ?: '/').': '.implode('; ', $messages))->implode(' | ');

        parent::__construct("Invalid [{$type}] payload: {$summary}");
    }
}
