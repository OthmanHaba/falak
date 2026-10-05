<?php

namespace Falak\Sites\Contracts\Exceptions;

use RuntimeException;

/**
 * The compose file cannot be rendered for a release (invalid YAML, policy violation, unknown public service,
 * `build:` service without a built image …). The message is shown to users (deployment error).
 */
final class ComposeRenderException extends RuntimeException {}
