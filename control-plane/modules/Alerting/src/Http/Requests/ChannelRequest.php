<?php

namespace Falak\Alerting\Http\Requests;

use Falak\Alerting\Domain\Enums\ChannelType;
use Falak\Alerting\Domain\Models\Channel;
use Falak\Alerting\Infrastructure\Senders\SenderRegistry;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule as ValidationRule;

final class ChannelRequest extends FormRequest
{
    public function authorize(): Response|bool
    {
        $channel = $this->route('channel');

        if ($channel instanceof Channel) {
            return Gate::inspect('update', $channel);
        }

        return app(OrganizationAccess::class)->can($this->user(), app(CurrentOrganization::class)->requireId(), 'alerting.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $channel = $this->route('channel');
        $channel = $channel instanceof Channel ? $channel : null;
        $type = $channel?->type ?? ChannelType::tryFrom((string) $this->input('type'));
        $organizationId = $channel?->organization_id ?? app(CurrentOrganization::class)->requireId();

        $rules = [
            'name' => ['required', 'string', 'max:100', ValidationRule::unique('alerting_channels')->where('organization_id', $organizationId)->ignore($channel?->id)],
            'enabled' => ['sometimes', 'boolean'],
            'config' => ['required', 'array'],
        ];

        if (! $channel) {
            $rules['type'] = ['required', ValidationRule::enum(ChannelType::class)];
        }

        if ($type) {
            $sender = app(SenderRegistry::class)->for($type);

            foreach ($sender->rules() as $key => $keyRules) {
                // On update, secrets may be left blank to keep the stored value.
                if ($channel && in_array(substr($key, 7), $sender->secretKeys(), true)) {
                    $keyRules = array_map(fn ($rule) => $rule === 'required' ? 'nullable' : $rule, $keyRules);
                }

                $rules[$key] = $keyRules;
            }
        }

        return $rules;
    }
}
