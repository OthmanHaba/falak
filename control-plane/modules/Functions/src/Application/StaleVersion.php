<?php

namespace Falak\Functions\Application;

use Falak\Functions\Domain\Models\FunctionVersion;
use RuntimeException;

/**
 * Someone deployed a newer version since the editor loaded its base: the editor shows the diff before deploying.
 */
final class StaleVersion extends RuntimeException
{
    public function __construct(public readonly FunctionVersion $head)
    {
        parent::__construct("Version {$head->number} was deployed".($head->author_name ? " by {$head->author_name}" : '').' after you started editing.');
    }
}
