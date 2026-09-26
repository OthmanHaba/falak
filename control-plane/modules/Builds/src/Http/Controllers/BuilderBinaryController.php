<?php

namespace Kiln\Builds\Http\Controllers;

use Kiln\Kernel\Http\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * GET /install/builder/linux-{arch} — kiln-builder binaries for builder servers.
 */
final class BuilderBinaryController extends Controller
{
    public function __invoke(string $arch): BinaryFileResponse
    {
        $file = rtrim((string) config('builds.builder_binary.binaries_path'), '/')."/kiln-builder-linux-{$arch}";

        abort_unless(is_file($file), 404, 'No kiln-builder build for this architecture is published.');

        return response()->download($file, "kiln-builder-linux-{$arch}", [
            'Content-Type' => 'application/octet-stream',
            'X-Checksum-Sha256' => (string) hash_file('sha256', $file),
        ]);
    }
}
