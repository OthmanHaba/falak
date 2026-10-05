<?php

namespace Falak\Alerting\Http\Requests;

use DateTimeZone;
use Falak\Alerting\Contracts\Severity;
use Falak\Alerting\Domain\Models\Rule;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule as ValidationRule;

final class RuleRequest extends FormRequest
{
    public function authorize(): Response|bool
    {
        $rule = $this->route('rule');

        if ($rule instanceof Rule) {
            return Gate::inspect('update', $rule);
        }

        return app(OrganizationAccess::class)->can($this->user(), app(CurrentOrganization::class)->requireId(), 'alerting.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rule = $this->route('rule');
        $organizationId = $rule instanceof Rule ? $rule->organization_id : app(CurrentOrganization::class)->requireId();

        return [
            'name' => ['required', 'string', 'max:100'],
            'enabled' => ['sometimes', 'boolean'],
            'event_types' => ['required', 'array', 'min:1', 'max:50'],
            'event_types.*' => ['required', 'string', 'max:100', 'regex:/^(\*|[a-z0-9_]+(\.[a-z0-9_]+)*(\.\*)?)$/'],
            'min_severity' => ['required', ValidationRule::enum(Severity::class)],
            'channel_ids' => ['present', 'array', 'max:20'],
            'channel_ids.*' => ['required', 'string', ValidationRule::exists('alerting_channels', 'id')->where('organization_id', $organizationId)],
            'rate_limit_per_hour' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'quiet_hours' => ['nullable', 'array'],
            'quiet_hours.enabled' => ['sometimes', 'boolean'],
            'quiet_hours.start' => ['required_if_accepted:quiet_hours.enabled', 'nullable', 'date_format:H:i'],
            'quiet_hours.end' => ['required_if_accepted:quiet_hours.enabled', 'nullable', 'date_format:H:i'],
            'quiet_hours.timezone' => ['nullable', 'string', ValidationRule::in(DateTimeZone::listIdentifiers())],
            'quiet_hours.days' => ['nullable', 'array'],
            'quiet_hours.days.*' => ['integer', 'between:1,7'],
            'quiet_hours.allow_critical' => ['sometimes', 'boolean'],
        ];
    }
}
