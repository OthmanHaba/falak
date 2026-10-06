<?php

namespace Falak\Kernel\Security;

use RuntimeException;

/**
 * The key-encryption key can't be used: the KEK file is missing, has the wrong size or loose permissions,
 * or the KMS / Vault provider is misconfigured or unreachable. Nothing sealed can be read until it is fixed.
 */
final class KeyUnavailable extends RuntimeException {}
