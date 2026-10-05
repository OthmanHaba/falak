<?php

namespace Falak\Alerting\Infrastructure\Senders;

use RuntimeException;

/** A channel rejected or could not receive a message. The message never contains channel secrets. */
final class DeliveryFailed extends RuntimeException {}
