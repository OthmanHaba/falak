<?php

namespace Kiln\SourceControl\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\SourceControl\Contracts\ProviderType;

/**
 * Shared by the web form and the API: which auth types each provider accepts, and the credentials to store.
 */
final class StoreConnectionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = app(CurrentOrganization::class)->requireId();

        return [
            'provider' => ['required', Rule::enum(ProviderType::class)],
            'name' => ['nullable', 'string', 'max:100', Rule::unique('source_control_connections')->where('organization_id', $organizationId)],
            'auth_type' => ['required', Rule::in(['token', 'basic', 'none'])],
            'base_url' => ['nullable', 'url:https,http', 'max:255'],
            'token' => ['required_if:auth_type,token', 'nullable', 'string', 'max:2000'],
            'username' => ['required_if:auth_type,basic', 'nullable', 'string', 'max:255'],
            'password' => ['required_if:auth_type,basic', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $provider = $this->provider();
            $allowed = match ($provider) {
                ProviderType::GitHub, ProviderType::GitLab => ['token'],
                ProviderType::Bitbucket => ['basic', 'token'],
                ProviderType::Custom => ['none'],
            };

            if (! in_array($this->input('auth_type'), $allowed, true)) {
                $validator->errors()->add('auth_type', "{$provider->label()} connections use: ".implode(', ', $allowed).'.');
            }

            if ($provider === ProviderType::Bitbucket && $this->filled('base_url')) {
                $validator->errors()->add('base_url', 'Bitbucket Server is not supported; use a custom git connection.');
            }
        }];
    }

    public function provider(): ProviderType
    {
        return ProviderType::from((string) $this->input('provider'));
    }

    /**
     * @return array<string, string>
     */
    public function credentials(): array
    {
        return match ($this->validated('auth_type')) {
            'token' => ['token' => (string) $this->validated('token')],
            'basic' => ['username' => (string) $this->validated('username'), 'password' => (string) $this->validated('password')],
            default => [],
        };
    }
}
