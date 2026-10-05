<?php

namespace Falak\Databases\Infrastructure\ObjectStorage;

use RuntimeException;

/**
 * An object-storage request made by the control plane failed. Messages never contain credentials.
 */
final class StorageRequestFailed extends RuntimeException {}
