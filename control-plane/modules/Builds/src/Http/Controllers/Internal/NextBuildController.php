<?php

namespace Kiln\Builds\Http\Controllers\Internal;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Kiln\Builds\Application\Actions\AssignBuild;
use Kiln\Builds\Domain\Models\Builder;
use Kiln\Kernel\Http\Controller;

/**
 * GET /api/internal/builds/next?wait=<s>&builder=<name> — long-poll: 200 + job JSON, or 204.
 */
final class NextBuildController extends Controller
{
    public function __invoke(Request $request, AssignBuild $assign): JsonResponse|Response
    {
        /** @var Builder $builder */
        $builder = $request->attributes->get('builder');
        $wait = max(0, min((int) $request->query('wait', '0'), (int) config('builds.long_poll_max_seconds', 25)));
        $deadline = microtime(true) + $wait;
        $interval = max(100, (int) config('builds.long_poll_interval_ms', 1000)) * 1000;

        do {
            if ($assigned = $assign($builder)) {
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
