<?php

namespace Kiln\Apm\Watchers;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Kiln\Apm\Recorder;
use Kiln\Apm\Span;
use Throwable;

/**
 * A top-level artisan command is its own trace; commands called from a request / job /
 * another command are child spans.
 */
final class CommandWatcher
{
    /** @var list<array{name: string, span: ?Span, root: bool}> */
    private array $stack = [];

    public function __construct(private Recorder $recorder)
    {
    }

    public function register(Dispatcher $events): void
    {
        $events->listen(CommandStarting::class, function (CommandStarting $event) {
            try {
                $name = (string) $event->command;

                if ($name === '' || ! $this->recorder->typeEnabled('command') || $this->recorder->ignored('commands', $name)) {
                    return;
                }

                $attributes = ['kiln.command.name' => $name];

                if ($this->recorder->active()) {
                    $this->stack[] = ['name' => $name, 'span' => $this->recorder->startSpan('command', $name, Span::KIND_INTERNAL, $attributes), 'root' => false];
                } else {
                    $this->stack[] = ['name' => $name, 'span' => $this->recorder->beginTrace('command', $name, Span::KIND_INTERNAL, $attributes), 'root' => true];
                }
            } catch (Throwable) {
            }
        });

        $events->listen(CommandFinished::class, function (CommandFinished $event) {
            try {
                $name = (string) $event->command;

                for ($i = count($this->stack) - 1; $i >= 0; $i--) {
                    if ($this->stack[$i]['name'] === $name) {
                        break;
                    }
                }

                if ($i < 0) {
                    return;
                }

                ['span' => $span, 'root' => $root] = $this->stack[$i];
                array_splice($this->stack, $i, 1);

                if ($span !== null) {
                    $span->attributes['process.exit.code'] = (int) $event->exitCode;

                    if ((int) $event->exitCode !== 0) {
                        $span->status = Span::STATUS_ERROR;

                        // The console kernel reports the escaping exception right before it
                        // returns a non-zero exit code: treat the last reported one as unhandled.
                        if ($span->exceptions !== []) {
                            $last = array_key_last($span->exceptions);
                            $span->exceptions[$last][2] = false;
                        }
                    }
                }

                if ($root) {
                    $this->recorder->endTrace();
                    $this->recorder->flush();
                } else {
                    $this->recorder->endSpan($span);
                }
            } catch (Throwable) {
            }
        });
    }

    public function reset(): void
    {
        $this->stack = [];
    }
}
