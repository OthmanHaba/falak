<?php

namespace Falak\Network\Http\Requests;

use Closure;
use Falak\Network\Domain\Enums\RuleAction;
use Falak\Network\Domain\Enums\RuleProtocol;
use Falak\Network\Domain\Support\AddressRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class FirewallRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorized in the controller (server-scoped)
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'action' => ['required', Rule::enum(RuleAction::class)],
            'protocol' => ['required', Rule::enum(RuleProtocol::class)],
            'port' => ['nullable', 'string', 'max:11', function (string $attribute, mixed $value, Closure $fail) {
                if (! AddressRules::isPort((string) $value)) {
                    $fail('Enter a port (1-65535) or a range such as 8000-8100.');
                }
            }],
            'source' => ['nullable', 'string', 'max:64', function (string $attribute, mixed $value, Closure $fail) {
                if (! AddressRules::isSource((string) $value)) {
                    $fail('Enter an IPv4/IPv6 address or CIDR, e.g. 203.0.113.0/24.');
                }
            }],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'port' => is_string($this->input('port')) ? trim($this->input('port')) ?: null : $this->input('port'),
            'source' => is_string($this->input('source')) ? trim($this->input('source')) ?: null : $this->input('source'),
        ]);
    }
}
