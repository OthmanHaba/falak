<?php

namespace Falak\Fleet\Http\Controllers\Agent;

use Falak\Fleet\Application\Actions\IngestCommandEvents;
use Falak\Fleet\Infrastructure\ProtocolSchemas;
use Falak\Kernel\Http\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * POST /agent/v1/commands/{id}/events — NDJSON of event.schema.json → 204 (idempotent on (command_id, seq)).
 */
final class CommandEventsController extends Controller
{
    use ReadsProtocolDocuments;

    public function __invoke(Request $request, string $command, ProtocolSchemas $schemas, IngestCommandEvents $ingest): Response
    {
        $model = $this->agent($request)->commands()->findOrFail($command);

        $events = $this->ndjson($request, $schemas, 'event.schema.json', (int) config('fleet.commands.max_event_batch_bytes'), 10_000, $raw);

        foreach ($events as $index => $event) {
            if ($event['command_id'] !== $model->id) {
                throw ValidationException::withMessages(["line.{$index}/command_id" => 'Every event must belong to the command in the URL.']);
            }
        }

        // Structured results are checked against the command schema's $defs/result on the raw JSON
        // (decoding to PHP arrays would turn {} into []). A mismatch is logged, never rejected.
        foreach ($raw as $object) {
            if (($object->kind ?? null) === 'finished' && isset($object->result) && ($errors = $schemas->validateCommandResult($model->type, $object->result)) !== []) {
                Log::warning('falak.fleet: command result does not match its schema', ['command_id' => $model->id, 'type' => $model->type, 'errors' => $errors]);
            }
        }

        $ingest($model, $events);

        return response()->noContent();
    }
}
