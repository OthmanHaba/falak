<?php

namespace Falak\Fleet\Http\Controllers;

use Illuminate\Http\Response;
use Falak\Fleet\Domain\Models\InstallToken;
use Falak\Fleet\Infrastructure\AgentBinaries;
use Falak\Fleet\Infrastructure\InstallScript;
use Falak\Kernel\Http\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Public, stateless installer endpoints (`curl -fsSL <panel>/install/<token> | sudo sh`).
 */
final class InstallController extends Controller
{
    public function script(string $token, InstallScript $script): Response
    {
        $valid = InstallToken::query()->usable()->where('token_hash', InstallToken::hash($token))->exists();

        if (! $valid) {
            return response("echo 'falak: this install link is invalid, expired or already used' >&2; exit 1\n", 404)
                ->header('Content-Type', 'text/x-shellscript; charset=utf-8');
        }

        return response($script->render($token), 200, [
            'Content-Type' => 'text/x-shellscript; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function binary(string $arch, AgentBinaries $binaries): BinaryFileResponse
    {
        $file = $binaries->file($arch) ?? abort(404, 'No falak-agent build for this architecture is published.');

        return response()->download($file, "falak-agent-linux-{$arch}", [
            'Content-Type' => 'application/octet-stream',
            'X-Checksum-Sha256' => (string) $binaries->checksum($arch),
        ]);
    }
}
