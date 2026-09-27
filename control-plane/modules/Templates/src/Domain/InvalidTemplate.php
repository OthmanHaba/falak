<?php

namespace Kiln\Templates\Domain;

use RuntimeException;

/**
 * A template that does not satisfy the schema / validation rules. Errors are keyed by location
 * (`template.yaml: inputs[2].key`, `compose.yaml: services.web.image`).
 */
final class InvalidTemplate extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(public readonly array $errors, string $context = 'Invalid template')
    {
        parent::__construct($context.': '.implode('; ', $errors));
    }
}
