<?php

namespace Kiln\Sites\Contracts;

/**
 * Where a compose site's compose file comes from (docs/COMPOSE_TEMPLATES.md §1.1).
 */
enum ComposeSource: string
{
    /** `compose_file` in the site's git repository (built by kiln-builder in docker mode). */
    case Repo = 'repo';
    /** `compose_content` stored in Kiln and versioned like environment variables. */
    case Inline = 'inline';

    public function label(): string
    {
        return match ($this) {
            self::Repo => 'Repository',
            self::Inline => 'Inline',
        };
    }
}
