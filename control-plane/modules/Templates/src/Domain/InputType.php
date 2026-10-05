<?php

namespace Falak\Templates\Domain;

/**
 * Types of template inputs (docs/COMPOSE_TEMPLATES.md §2); they drive the configure form and validation.
 */
enum InputType: string
{
    case String = 'string';
    case Secret = 'secret';
    case Email = 'email';
    case Number = 'number';
    case Boolean = 'boolean';
    case Select = 'select';
    case Domain = 'domain';

    /** Whether values are masked in the form and treated as secrets. */
    public function isSecret(): bool
    {
        return $this === self::Secret;
    }

    public function canGenerate(): bool
    {
        return $this === self::String || $this === self::Secret;
    }
}
