<?php

namespace Falak\Secrets\Infrastructure\Providers;

use RuntimeException;

/**
 * A provider call failed (unreachable, refused, not found, unexpected answer). The message is shown to users and
 * stored as the provider's last error: it names the problem, never a credential, a token or a value, and never
 * echoes the provider's response body.
 */
final class ProviderFailure extends RuntimeException {}
