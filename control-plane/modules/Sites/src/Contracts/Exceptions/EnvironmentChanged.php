<?php

namespace Falak\Sites\Contracts\Exceptions;

use RuntimeException;

/** The site's environment was saved by someone else since the version a change was based on. */
final class EnvironmentChanged extends RuntimeException {}
