<?php

namespace Kiln\Fleet\Http\Controllers\Agent;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kiln\Fleet\Application\Actions\ClaimCommands;
use Kiln\Fleet\Domain\Models\Command;
use Kiln\Fleet\Infrastructure\Signals\CommandSignal;
use Kiln\Kernel\Http\Controller;

/**
 * GET /agent/v1/commands?wait=30 — long-poll; returns as soon as commands are queued.
 */
final class CommandPollController extends Controller
{
    use ReadsProtocolDocuments;

    public function __invoke(Request $request, ClaimCommands $claim, CommandSignal $signal): JsonResponse
    {
        $agent = $this->agent($request);
        $wait = max(0, min((int) $request->query('wait', '0'), (int) config('fleet.long_poll_max_seconds', 60)));

        if ($wait > 0) {
            set_time_limit($wait + 15);
        }

        $commands = $signal->wait($agent->id, $wait, fn () => $claim($agent));

        return response()->json([
            'commands' => array_map(fn (Command $command) => $command->envelope(), $commands),
        ]);
    }
}
