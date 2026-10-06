<?php

namespace Falak\Secrets\Contracts\Exceptions;

use RuntimeException;

/**
 * A linked secret's provider could not produce a value. The message is shown to users (deploy errors): it must
 * name the problem, never contain the value or the provider's credentials.
 */
final class SecretProviderUnavailable extends RuntimeException {}
