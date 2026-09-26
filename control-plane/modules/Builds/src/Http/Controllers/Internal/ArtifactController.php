<?php

namespace Kiln\Builds\Http\Controllers\Internal;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kiln\Builds\Application\Artifacts\ArtifactStorage;
use Kiln\Builds\Infrastructure\Artifacts\LocalArtifactStorage;
use Kiln\Kernel\Http\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Local artifact driver: presigned (signed, expiring) PUT for builders and GET for agents.
 */
final class ArtifactController extends Controller
{
    public function upload(Request $request, string $key, ArtifactStorage $storage): JsonResponse
    {
        $local = $this->local($request, $storage, $key);
        $path = $local->path($key);
        $max = (int) config('builds.artifacts.max_upload_bytes', 4 * 1024 ** 3);

        if (! is_dir(dirname($path)) && ! @mkdir(dirname($path), 0750, true) && ! is_dir(dirname($path))) {
            abort(500, 'Artifact storage is not writable.');
        }

        $tmp = $path.'.upload-'.bin2hex(random_bytes(6));
        $in = $request->getContent(true);
        $out = fopen($tmp, 'wb');

        if (! is_resource($in) || $out === false) {
            abort(500, 'Artifact storage is not writable.');
        }

        $written = 0;

        while (! feof($in)) {
            $chunk = fread($in, 1024 * 1024);

            if ($chunk === false) {
                break;
            }

            $written += strlen($chunk);

            if ($written > $max) {
                fclose($out);
                @unlink($tmp);

                return response()->json(['message' => 'Artifact too large.'], 413);
            }

            fwrite($out, $chunk);
        }

        fclose($out);
        rename($tmp, $path);

        return response()->json(['size_bytes' => $written, 'sha256' => hash_file('sha256', $path)], 201);
    }

    public function download(Request $request, string $key, ArtifactStorage $storage): BinaryFileResponse
    {
        $path = $this->local($request, $storage, $key)->path($key);

        abort_unless(is_file($path), 404, 'Artifact not found.');

        return response()->download($path, basename($key), ['Content-Type' => 'application/octet-stream']);
    }

    private function local(Request $request, ArtifactStorage $storage, string $key): LocalArtifactStorage
    {
        abort_unless($storage instanceof LocalArtifactStorage, 404);
        abort_unless($request->hasValidSignature(false), 403, 'Invalid or expired artifact URL.');
        abort_unless(LocalArtifactStorage::validKey($key), 404);

        return $storage;
    }
}
