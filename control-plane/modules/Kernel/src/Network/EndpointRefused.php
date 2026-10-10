<?php

namespace Falak\Kernel\Network;

use RuntimeException;

/**
 * An endpoint the {@see EndpointGuard} refuses (not https, unresolvable, or on a refused address). The message
 * names the host and why; it is shown to users.
 */
final class EndpointRefused extends RuntimeException {}
