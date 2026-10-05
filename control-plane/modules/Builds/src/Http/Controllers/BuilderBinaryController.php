<?php

namespace Falak\Builds\Http\Controllers;

use Falak\Kernel\Http\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * GET /install/builder/linux-{arch} — falak-builder binaries for builder servers.
 */
final class BuilderBinaryController extends Controller
{
    public function __invoke(string $arch): BinaryFileResponse
    {
        $file = rtrim((string) config('builds.builder_binary.binaries_path'), '/')."/falak-builder-linux-{$arch}";

        abort_unless(is_file($file), 404, 'No falak-builder build for this architecture is published.');

        return response()->download($file, "falak-builder-linux-{$arch}", [
            'Content-Type' => 'application/octet-stream',
            'X-Checksum-Sha256' => (string) hash_file('sha256', $file),
        ]);
    }
}
