<?php

namespace Kiln\Terminal\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Identity\Contracts\OrganizationDirectory;
use Kiln\Kernel\Http\Controller;
use Kiln\Terminal\Domain\Models\TerminalSession;
use Kiln\Terminal\Infrastructure\AsciicastWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class RecordingController extends Controller
{
    use PresentsSessions;

    public function show(TerminalSession $session, AuditLog $audit, OrganizationDirectory $directory): Response
    {
        $this->authorize('replay', $session);

        $audit->record('terminal.recording_viewed', 'terminal_session', $session->id, ['server_id' => $session->server_id], $session->organization_id);

        return Inertia::render('Terminal/Playback', [
            'session' => $this->present($session, $directory),
            'castUrl' => route('terminal.sessions.recording.cast', $session),
        ]);
    }

    public function download(TerminalSession $session, AsciicastWriter $writer): StreamedResponse
    {
        $this->authorize('replay', $session);

        $name = "terminal-{$session->server_name}-{$session->id}.cast";

        return response()->streamDownload(function () use ($session, $writer) {
            echo $writer->write($session);
        }, preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? $name, [
            'Content-Type' => 'application/x-asciicast',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
