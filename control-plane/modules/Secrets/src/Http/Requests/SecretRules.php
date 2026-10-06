<?php

namespace Falak\Secrets\Http\Requests;

use Falak\Secrets\Contracts\SecretScope;
use Falak\Secrets\Domain\Enums\SecretKind;
use Illuminate\Validation\Rule;

/**
 * Validation shared by the web and API controllers.
 */
final class SecretRules
{
    /** Values are environment variable values (the .env limit is 64 KiB for the whole file). */
    public const MAX_VALUE = 65535;

    /**
     * @return array<string, mixed>
     */
    public static function create(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'regex:/^[A-Z_][A-Z0-9_]*$/'],
            'scope' => ['required', Rule::enum(SecretScope::class)],
            'scope_id' => ['required', 'string', 'max:26'],
            'kind' => ['sometimes', Rule::enum(SecretKind::class)],
            'value' => ['nullable', 'string', 'max:'.self::MAX_VALUE, 'required_unless:kind,linked'],
            'reference' => ['nullable', 'string', 'max:2000', 'required_if:kind,linked'],
            ...self::metadata(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function metadata(): array
    {
        return [
            'sensitive' => ['sometimes', 'boolean'],
            'available_to_previews' => ['sometimes', 'boolean'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'rotation_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
            // Linked secrets; the action checks it is one of the organization's providers.
            'provider_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function value(): array
    {
        return ['value' => ['required', 'string', 'max:'.self::MAX_VALUE]];
    }

    /**
     * @return array<string, mixed>
     */
    public static function promote(): array
    {
        return [
            'service_id' => ['required', 'string', 'max:26'],
            'key' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/'],
            'name' => ['required', 'string', 'max:255', 'regex:/^[A-Z_][A-Z0-9_]*$/'],
            'sensitive' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'name.regex' => 'Use an environment variable name: capital letters, digits and underscores, not starting with a digit.',
            'value.required_unless' => 'Enter a value.',
            'reference.required_if' => 'Enter the reference of the value at the provider.',
        ];
    }
}
