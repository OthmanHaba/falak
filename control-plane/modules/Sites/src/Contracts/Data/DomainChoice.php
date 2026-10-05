<?php

namespace Falak\Sites\Contracts\Data;

use Closure;
use Illuminate\Validation\ValidationException;
use Falak\Sites\Contracts\DomainType;

/**
 * A domain picked in a create form or sent to the API: `{type: generated|test|custom, name?}`. A plain string is a
 * custom domain (the format before domain types existed).
 */
final readonly class DomainChoice
{
    public function __construct(
        public DomainType $type,
        public ?string $name = null,
    ) {}

    /**
     * Null when nothing was chosen (null, '' or a missing type): the caller applies its default.
     *
     * @throws ValidationException keyed $field
     */
    public static function fromInput(mixed $input, string $field): ?self
    {
        if ($input === null || (is_string($input) && trim($input) === '')) {
            return null;
        }

        if (is_string($input)) {
            return new self(DomainType::Custom, self::normalize($input));
        }

        if (! is_array($input)) {
            throw ValidationException::withMessages([$field => 'The domain must be a name or {type, name}.']);
        }

        $type = DomainType::tryFrom((string) ($input['type'] ?? ''));

        if ($type === null) {
            throw ValidationException::withMessages([$field => 'The domain type must be generated, test or custom.']);
        }

        $name = isset($input['name']) && is_string($input['name']) && trim($input['name']) !== '' ? self::normalize($input['name']) : null;

        if ($type === DomainType::Custom && $name === null) {
            throw ValidationException::withMessages([$field => 'Enter a domain name like app.example.com.']);
        }

        return new self($type, $type === DomainType::Custom ? $name : null);
    }

    /**
     * Validation rule: a domain name, or {type: generated|test|custom, name?} (resolved later by SiteDomains).
     */
    public static function rule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (is_string($value)) {
                if (strlen($value) > 253) {
                    $fail('The domain may not be longer than 253 characters.');
                }

                return;
            }

            if (! is_array($value) || array_diff(array_keys($value), ['type', 'name']) !== []
                || DomainType::tryFrom((string) ($value['type'] ?? '')) === null
                || (isset($value['name']) && (! is_string($value['name']) || strlen($value['name']) > 253))) {
                $fail('The domain must be a name or {type: generated|test|custom, name}.');
            }
        };
    }

    public static function normalize(string $name): string
    {
        $name = strtolower(trim($name));
        $name = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $name); // pasted URL: keep the host

        return rtrim(explode('/', $name, 2)[0], '.');
    }

    /**
     * @return array{type: string, name: ?string}
     */
    public function toArray(): array
    {
        return ['type' => $this->type->value, 'name' => $this->name];
    }
}
