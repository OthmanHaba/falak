<?php

namespace Falak\Fleet\Http\Controllers\Agent;

use Falak\Fleet\Application\Actions\ClaimCommands;
use Falak\Fleet\Application\CommandRedelivery;
use Falak\Fleet\Domain\Models\Command;
use Falak\Fleet\Infrastructure\Signals\CommandSignal;
use Falak\Kernel\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /agent/v1/commands?wait=30 — long-poll; returns as soon as commands are queued.
 */
final class CommandPollController extends Controller
{
    use ReadsProtocolDocuments;

    public function __invoke(Request $request, ClaimCommands $claim, CommandSignal $signal, CommandRedelivery $redelivery): JsonResponse
    {
        $agent = $this->agent($request);
        $session = $this->session($request);
        $redelivery->observeSession($agent, $session);
        $wait = max(0, min((int) $request->query('wait', '0'), (int) config('fleet.long_poll_max_seconds', 60)));

        // Not under tests: the limit would apply to the whole test process and kill later tests.
        if ($wait > 0 && ! app()->runningUnitTests()) {
            set_time_limit($wait + 15);
        }

        $commands = $signal->wait($agent->id, $wait, fn () => $claim($agent, $session));

        return response()->json([
            'commands' => array_map(fn (Command $command) => $command->envelope(), $commands),
        ]);
    }
}
