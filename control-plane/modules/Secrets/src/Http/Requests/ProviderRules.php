<?php

namespace Falak\Secrets\Http\Requests;

use Falak\Secrets\Domain\Enums\ProviderType;
use Illuminate\Validation\Rule;

/**
 * Validation of secret providers shared by the web and API controllers. The settings themselves (per type,
 * required ones, patterns, endpoint checks) are validated by SaveSecretProvider.
 */
final class ProviderRules
{
    /**
     * @return array<string, mixed>
     */
    public static function create(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::enum(ProviderType::class)],
            ...self::common(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function update(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            ...self::common(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function common(): array
    {
        return [
            'config' => ['sometimes', 'array'],
            'config.*' => ['nullable', 'string', 'max:20000'],
            'allow_private_network' => ['sometimes', 'boolean'],
            // 0: always ask the provider (the cached value is then only the fallback).
            'cache_ttl_seconds' => ['sometimes', 'integer', 'min:0', 'max:86400'],
        ];
    }
}
