<?php

namespace Kiln\Builds\Http\Controllers\Internal;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Kiln\Builds\Application\Actions\IngestBuildEvents;
use Kiln\Builds\Contracts\BuildStatus;
use Kiln\Builds\Domain\Models\Build;
use Kiln\Builds\Domain\Models\Builder;
use Kiln\Kernel\Http\Controller;

/**
 * POST /api/internal/builds/{build}/events — NDJSON of event.schema.json (command_id = build id).
 * Idempotent on (build, seq). 410 tells the builder the build was cancelled and must be aborted.
 */
final class BuildEventsController extends Controller
{
    private const KINDS = ['started', 'output', 'progress', 'finished'];

    private const MAX_BYTES = 8 * 1024 * 1024;

    public function __invoke(Request $request, string $build, IngestBuildEvents $ingest): JsonResponse|Response
    {
        /** @var Builder $builder */
        $builder = $request->attributes->get('builder');
        $model = Build::query()->find(strtolower($build));

        if (! $model || $model->builder_id !== $builder->id) {
            return response()->json(['message' => 'Unknown build.'], 404);
        }

        if ($model->status === BuildStatus::Cancelled) {
            return response()->json(['message' => 'The build was cancelled.'], 410);
        }

        $body = $request->getContent();

        if (strlen($body) > self::MAX_BYTES) {
            return response()->json(['message' => 'Event batch too large.'], 413);
        }

        $events = [];

        foreach (preg_split('/\r?\n/', $body) ?: [] as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            $event = json_decode($line, true);

            if (! is_array($event) || ! is_int($event['seq'] ?? null) || $event['seq'] < 0 || ! in_array($event['kind'] ?? null, self::KINDS, true) || ! is_string($event['at'] ?? null)) {
                return response()->json(['message' => 'Invalid event on line '.($index + 1).'.', 'errors' => ['events' => ['Each line must be an event.schema.json object.']]], 422);
            }

            if (isset($event['command_id']) && strtolower((string) $event['command_id']) !== $model->id) {
                return response()->json(['message' => 'Event command_id does not match the build.'], 422);
            }

            $events[] = $event;
        }

        if (! $model->status->isTerminal()) {
            $ingest($model, $events);
        }

        return response()->noContent();
    }
}
