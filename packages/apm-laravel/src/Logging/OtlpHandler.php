<?php

namespace Falak\Apm\Logging;

use Illuminate\Container\Container;
use Falak\Apm\Recorder;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Monolog handler that buffers records as OTLP logs (correlated with the active trace/span).
 *
 *   'channels' => ['falak' => ['driver' => 'monolog', 'handler' => Falak\Apm\Logging\OtlpHandler::class]]
 *
 * Set `falak-apm.logs_via` to 'handler' when using it to avoid double capture.
 */
final class OtlpHandler extends AbstractProcessingHandler
{
    public function __construct(int|string|Level $level = Level::Debug, bool $bubble = true, private ?Recorder $recorder = null)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        try {
            $recorder = $this->recorder ?? Container::getInstance()->make(Recorder::class);
            $recorder->recordLog(strtolower($record->level->getName()), $record->message, $record->context + ($record->extra === [] ? [] : ['extra' => $record->extra]));
        } catch (Throwable) {
        }
    }
}
