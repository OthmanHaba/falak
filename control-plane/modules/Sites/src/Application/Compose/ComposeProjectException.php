<?php

namespace Kiln\Sites\Application\Compose;

use RuntimeException;

/**
 * A compose project can't be loaded (missing file, YAML error, include/extends problem).
 */
final class ComposeProjectException extends RuntimeException {}
