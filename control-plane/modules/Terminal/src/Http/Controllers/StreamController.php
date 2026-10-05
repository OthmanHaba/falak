<?php

namespace Falak\Terminal\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Kernel\Http\Controller;
use Falak\Terminal\Application\Actions\ResizeSession;
use Falak\Terminal\Application\Actions\SendInput;
use Falak\Terminal\Domain\Models\TerminalFrame;
use Falak\Terminal\Domain\Models\TerminalSession;

/**
 * The live data path of a session: keystrokes in (dedicated, rate-limited route), resize, and
 * catch-up of already-recorded output for viewers who attach mid-session.
 */
final class StreamController extends Controller
{
    public function input(Request $request, TerminalSession $session, SendInput $send): Response
    {
        $this->authorize('type', $session);

        $data = $request->validate([
            'data' => ['required', 'string', 'max:'.(int) ceil(((int) config('terminal.input_max_bytes', 16384)) / 3) * 4],
            'seq' => ['required', 'integer', 'min:0'],
            'stream' => ['required', 'uuid'],
        ]);

        $raw = base64_decode($data['data'], true);

        if ($raw === false || $raw === '') {
            throw ValidationException::withMessages(['data' => 'Input must be non-empty base64.']);
        }

        abort_if(strlen($raw) > (int) config('terminal.input_max_bytes', 16384), 413, 'Input batch too large.');
        abort_unless($session->isLive(), 409, 'The session is closed.');

        try {
            $send($session, $data['data'], (int) $data['seq'], strtolower((string) $data['stream']), (string) $request->user()?->getAuthIdentifier());
        } catch (AgentUnavailable) {
            abort(503, 'The server agent is not connected.');
        }

        return response()->noContent();
    }

    public function resize(Request $request, TerminalSession $session, ResizeSession $resize): Response
    {
        $this->authorize('type', $session);

        $data = $request->validate([
            'cols' => ['required', 'integer', 'min:1', 'max:1000'],
            'rows' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        abort_unless($session->isLive(), 409, 'The session is closed.');

        try {
            $resize($session, (int) $data['cols'], (int) $data['rows']);
        } catch (AgentUnavailable) {
            abort(503, 'The server agent is not connected.');
        }

        return response()->noContent();
    }

    public function frames(Request $request, TerminalSession $session): JsonResponse
    {
        $this->authorize('view', $session);

        $after = max(0, (int) $request->query('after', '0'));
        $limit = (int) config('terminal.frames_page_size', 500);

        $frames = TerminalFrame::query()
            ->where('session_id', $session->id)
            ->where('kind', TerminalFrame::OUTPUT)
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get(['id', 'fleet_seq', 'data']);

        $more = $frames->count() > $limit;
        $frames = $frames->take($limit);

        return response()->json([
            'frames' => $frames->map(fn (TerminalFrame $frame) => ['id' => $frame->id, 'seq' => $frame->fleet_seq, 'data' => $frame->data])->values(),
            'last_id' => $frames->last()->id ?? $after,
            'more' => $more,
            'status' => $session->status->value,
        ]);
    }
}
