<?php

namespace Kiln\Fleet\Infrastructure;

use JsonException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use stdClass;

/**
 * Validates documents against contracts/agent-protocol JSON Schemas (draft 2020-12).
 * Schemas reference each other by their $id under https://kiln.dev/agent-protocol/.
 */
final class ProtocolSchemas
{
    public const BASE_ID = 'https://kiln.dev/agent-protocol/';

    private ?Validator $validator = null;

    public function __construct(private readonly string $path) {}

    public function path(): string
    {
        return rtrim($this->path, '/');
    }

    public function hasCommand(string $type): bool
    {
        return preg_match('/^[a-z_]+(\.[a-z_]+)+$/', $type) === 1
            && is_file($this->path()."/commands/{$type}.schema.json");
    }

    /**
     * @return array<string, list<string>> errors keyed by JSON pointer (empty = valid)
     */
    public function validateCommand(string $type, mixed $payload): array
    {
        return $this->validate("commands/{$type}.schema.json", $payload);
    }

    /**
     * Validate a finished event's structured result against the command schema's $defs/result, when defined.
     *
     * @return array<string, list<string>>
     */
    public function validateCommandResult(string $type, mixed $result): array
    {
        if (! $this->hasCommand($type)) {
            return [];
        }

        $schema = json_decode((string) file_get_contents($this->path()."/commands/{$type}.schema.json"));

        if (! isset($schema->{'$defs'}->result)) {
            return [];
        }

        return $this->validate("commands/{$type}.schema.json#/\$defs/result", $result);
    }

    /**
     * @param  string  $schema  file relative to the contracts directory, e.g. "heartbeat.schema.json"
     * @return array<string, list<string>>
     */
    public function validate(string $schema, mixed $document): array
    {
        $result = $this->validator()->validate(self::toJson($document), self::BASE_ID.$schema);

        if ($result->isValid()) {
            return [];
        }

        /** @var array<string, list<string>> */
        return (new ErrorFormatter)->format($result->error(), true);
    }

    /**
     * Convert PHP arrays to the JSON data model (assoc arrays → objects, lists → arrays; [] → {} at the root).
     *
     * @throws JsonException
     */
    public static function toJson(mixed $document): mixed
    {
        if ($document === []) {
            return new stdClass;
        }

        return json_decode(json_encode($document, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES), false, 512, JSON_THROW_ON_ERROR);
    }

    private function validator(): Validator
    {
        if ($this->validator === null) {
            $this->validator = new Validator;
            $this->validator->setMaxErrors(10);
            $this->validator->resolver()?->registerPrefix(self::BASE_ID, $this->path().'/');
        }

        return $this->validator;
    }
}
