<?php

namespace Falak\Fleet\Http\Controllers\Agent;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Falak\Fleet\Domain\Models\Agent;
use Falak\Fleet\Http\Middleware\AuthenticateAgent;
use Falak\Fleet\Infrastructure\ProtocolSchemas;

trait ReadsProtocolDocuments
{
    /**
     * Decode the JSON body and validate it against a protocol schema.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    protected function document(Request $request, ProtocolSchemas $schemas, ?string $schema): array
    {
        $raw = json_decode($request->getContent());

        if (! is_object($raw)) {
            throw ValidationException::withMessages(['body' => 'The request body must be a JSON object.']);
        }

        if ($schema !== null) {
            $this->assertValid($schemas->validate($schema, $raw));
        }

        return json_decode((string) json_encode($raw), true);
    }

    /**
     * Decode an NDJSON body into objects (validated per line when a schema is given).
     *
     * @param  list<object>|null  $raw  receives the undecoded JSON objects (preserving {} vs [])
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    protected function ndjson(Request $request, ProtocolSchemas $schemas, ?string $schema, int $maxBytes, int $maxLines, ?array &$raw = null): array
    {
        $raw = [];

        $body = $request->getContent();

        if (strlen($body) > $maxBytes) {
            abort(413, 'Batch too large.');
        }

        $lines = array_values(array_filter(preg_split('/\r?\n/', $body) ?: [], fn (string $line) => trim($line) !== ''));

        if (count($lines) > $maxLines) {
            abort(413, 'Too many lines in batch.');
        }

        $documents = [];
        $errors = [];

        foreach ($lines as $index => $line) {
            $object = json_decode($line);

            if (! is_object($object)) {
                $errors["line.{$index}"] = ['Each line must be a JSON object.'];

                continue;
            }

            if ($schema !== null) {
                foreach ($schemas->validate($schema, $object) as $pointer => $messages) {
                    $errors["line.{$index}{$pointer}"] = $messages;
                }
            }

            $documents[] = json_decode((string) json_encode($object), true);
            $raw[] = $object;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $documents;
    }

    /**
     * @param  array<string, list<string>>  $errors
     *
     * @throws ValidationException
     */
    protected function assertValid(array $errors): void
    {
        if ($errors !== []) {
            throw ValidationException::withMessages(collect($errors)->mapWithKeys(fn ($messages, $pointer) => [($pointer === '/' ? 'body' : ltrim($pointer, '/')) => $messages])->all());
        }
    }

    protected function agent(Request $request): Agent
    {
        /** @var Agent */
        return $request->attributes->get(AuthenticateAgent::AGENT);
    }

    /**
     * The falak-agent process a request comes from (X-Falak-Agent-Session; older agents send none).
     *
     * @throws ValidationException
     */
    protected function session(Request $request): ?string
    {
        $session = $request->headers->get('X-Falak-Agent-Session');

        if ($session === null || $session === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $session) !== 1) {
            throw ValidationException::withMessages(['session' => 'X-Falak-Agent-Session must be 8-64 characters [A-Za-z0-9._:-].']);
        }

        return $session;
    }
}
