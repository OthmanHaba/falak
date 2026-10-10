<?php

namespace Falak\Kernel\Security;

use RuntimeException;

/**
 * A sealed value could not be opened: tampered with, copied to another column or record (AAD), or
 * sealed under a data key this install doesn't have.
 */
final class DecryptionFailed extends RuntimeException {}
