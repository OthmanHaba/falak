<?php

namespace Kiln\Builds\Http\Controllers\Internal;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Kiln\Builds\Application\Actions\AssignBuild;
use Kiln\Builds\Application\Actions\ReapOrphanedBuilds;
use Kiln\Builds\Domain\Models\Builder;
use Kiln\Kernel\Http\Controller;

/**
 * GET /api/internal/builds/next?wait=<s>&builder=<name>&run=<run id> — long-poll: 200 + job JSON, or 204. A poll
 * with a new run id first fails the builds an earlier process of the same builder name had claimed.
 */
final class NextBuildController extends Controller
{
    public function __invoke(Request $request, AssignBuild $assign, ReapOrphanedBuilds $reap): JsonResponse|Response
    {
        /** @var Builder $builder */
        $builder = $request->attributes->get('builder');
        $name = is_string($request->query('builder')) && $request->query('builder') !== '' ? mb_substr((string) $request->query('builder'), 0, 100) : null;
        $run = is_string($request->query('run')) && preg_match('/^[A-Za-z0-9_-]{8,64}$/', (string) $request->query('run')) === 1 ? (string) $request->query('run') : null;

        if ($run !== null) {
            $reap($builder, $run, $name);
        }
        $wait = max(0, min((int) $request->query('wait', '0'), (int) config('builds.long_poll_max_seconds', 25)));
        $deadline = microtime(true) + $wait;
        $interval = max(100, (int) config('builds.long_poll_interval_ms', 1000)) * 1000;

        do {
            if ($assigned = $assign($builder, $run, $name)) {
                return response()->json($assigned['job'], 200, [], JSON_UNESCAPED_SLASHES);
            }

            if (microtime(true) + $interval / 1e6 > $deadline) {
                break;
            }

            usleep($interval);
        } while (true);

        return response()->noContent();
    }
}
