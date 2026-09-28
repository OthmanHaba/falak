<?php

namespace Kiln\Builds\Http\Controllers\Internal;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Kiln\Builds\Domain\Models\Build;
use Kiln\Builds\Domain\Models\Builder;
use Kiln\Kernel\Http\Controller;

/**
 * POST /api/internal/builds/{build}/heartbeat — kiln-builder, every 20 s while it runs a build. 204; 410 when the
 * build is over on this side (cancelled, failed by the watchdog or reaped): the builder aborts it.
 */
final class BuildHeartbeatController extends Controller
{
    public function __invoke(Request $request, string $build): JsonResponse|Response
    {
        /** @var Builder $builder */
        $builder = $request->attributes->get('builder');
        $model = Build::query()->find(strtolower($build));

        if (! $model || $model->builder_id !== $builder->id) {
            return response()->json(['message' => 'Unknown build.'], 404);
        }

        if ($model->status->isTerminal()) {
            return response()->json(['message' => "The build is {$model->status->value}."], 410);
        }

        $model->forceFill(['heartbeat_at' => now()])->save();

        return response()->noContent();
    }
}
