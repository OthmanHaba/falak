<?php

namespace Kiln\Terminal\Application\Actions;

use Kiln\Fleet\Contracts\AgentGateway;
use Kiln\Fleet\Contracts\Exceptions\AgentUnavailable;
use Kiln\Terminal\Application\SessionTransitions;
use Kiln\Terminal\Domain\Models\TerminalFrame;
use Kiln\Terminal\Domain\Models\TerminalSession;

final class ResizeSession
{
    public function __construct(
        private readonly AgentGateway $agents,
        private readonly SessionTransitions $transitions,
    ) {}

    /**
     * @throws AgentUnavailable
     */
    public function __invoke(TerminalSession $session, int $cols, int $rows): void
    {
        if ($session->cols === $cols && $session->rows === $rows) {
            return;
        }

        $this->agents->dispatch(
            $session->server_id,
            'terminal.resize',
            ['session_id' => $session->id, 'cols' => $cols, 'rows' => $rows],
            30,
            "terminal.resize:{$session->id}:{$cols}x{$rows}:".now()->getPreciseTimestamp(3),
        );

        $session->forceFill(['cols' => $cols, 'rows' => $rows, 'last_activity_at' => now()])->save();

        TerminalFrame::query()->create([
            'session_id' => $session->id,
            'fleet_seq' => null,
            'kind' => TerminalFrame::RESIZE,
            'offset_ms' => $session->offsetFor(now()),
            'data' => "{$cols}x{$rows}",
        ]);

        $this->transitions->broadcast($session);
    }
}
