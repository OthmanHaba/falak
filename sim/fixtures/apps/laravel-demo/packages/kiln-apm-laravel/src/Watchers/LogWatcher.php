<?php

namespace Kiln\Apm\Watchers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\Events\MessageLogged;
use Kiln\Apm\Recorder;
use Stringable;

/**
 * Captures everything that goes through Laravel's logger (all channels). Use
 * Kiln\Apm\Logging\OtlpHandler instead for per-channel Monolog wiring.
 */
final class LogWatcher
{
    public function __construct(private Recorder $recorder)
    {
    }

    public function register(Dispatcher $events): void
    {
        $events->listen(MessageLogged::class, function (MessageLogged $event) {
            $message = $event->message;

            $this->recorder->recordLog(
                (string) $event->level,
                is_string($message) || $message instanceof Stringable ? (string) $message : (string) json_encode($message),
                $event->context,
            );
        });
    }
}
