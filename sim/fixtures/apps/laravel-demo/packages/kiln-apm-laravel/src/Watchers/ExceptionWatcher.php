<?php

namespace Kiln\Apm\Watchers;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Kiln\Apm\Recorder;
use Throwable;

/**
 * Every reported exception becomes an `exception` span event on the current span with
 * kiln.exception.handled=true. Request / job / command / schedule watchers flip it to
 * handled=false (and the span to ERROR) when the exception escaped the application code.
 */
final class ExceptionWatcher
{
    public function __construct(private Recorder $recorder)
    {
    }

    public function register(ExceptionHandler $handler): void
    {
        if (! method_exists($handler, 'reportable')) {
            return;
        }

        $recorder = $this->recorder;

        $handler->reportable(static function (Throwable $e) use ($recorder): void {
            try {
                $recorder->recordException($e, true);
            } catch (Throwable) {
            }
        });
    }
}
