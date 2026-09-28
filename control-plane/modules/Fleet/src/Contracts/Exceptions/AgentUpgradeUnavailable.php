<?php

namespace Kiln\Fleet\Contracts\Exceptions;

use RuntimeException;

/**
 * The agent cannot be upgraded right now (offline, unknown architecture, no published build, already current).
 */
final class AgentUpgradeUnavailable extends RuntimeException {}
