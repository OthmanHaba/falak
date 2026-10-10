<?php

namespace Falak\Alerting\Http\Controllers;

use DateTimeZone;
use Falak\Alerting\Application\Actions\DeleteRule;
use Falak\Alerting\Application\Actions\SaveRule;
use Falak\Alerting\Application\DefaultRulePack;
use Falak\Alerting\Contracts\AlertTypes;
use Falak\Alerting\Contracts\Severity;
use Falak\Alerting\Domain\Models\Channel;
use Falak\Alerting\Domain\Models\Rule;
use Falak\Alerting\Http\Requests\RuleRequest;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class RuleController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
    ) {}

    public function index(Request $request, AlertTypes $types, DefaultRulePack $pack): Response
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'alerting.view');

        $rules = Rule::query()->with('channels:id,name,type')->where('organization_id', $organizationId)->orderBy('name')->get();

        return Inertia::render('Alerting/Rules', [
            'rules' => $rules->map(fn (Rule $rule) => [
                'id' => $rule->id,
                'pack_key' => $rule->pack_key,
                'name' => $rule->name,
                'enabled' => $rule->enabled,
                'event_types' => $rule->event_types,
                'min_severity' => $rule->min_severity->value,
                'quiet_hours' => $rule->quiet_hours,
                'rate_limit_per_hour' => $rule->rate_limit_per_hour,
                'channels' => $rule->channels->map(fn (Channel $channel) => ['id' => $channel->id, 'name' => $channel->name, 'type' => $channel->type->value])->values(),
            ])->values(),
            'channels' => Channel::query()->where('organization_id', $organizationId)->orderBy('name')->get(['id', 'name', 'type', 'enabled'])
                ->map(fn (Channel $channel) => ['id' => $channel->id, 'name' => $channel->name, 'type' => $channel->type->value, 'enabled' => $channel->enabled])->values(),
            // No channel yet: the default pack only notifies in-app (the page prompts to add one).
            'hasChannel' => Channel::query()->where('organization_id', $organizationId)->exists(),
            // Channels but none is the default (several when alerting coverage arrived): the page asks to pick one.
            'defaultChannel' => Channel::query()->where('organization_id', $organizationId)->where('is_default', true)->value('name'),
            // The default rule pack's areas (rules with a pack_key), in the registry's group order.
            'packAreas' => collect($pack->areas())->map(fn (array $area, string $key) => ['key' => $key, 'group' => $area['group'], 'patterns' => $area['patterns']])->values(),
            'alertTypes' => collect($types->all())->map(fn (array $type) => [...$type, 'severity' => $type['severity']->value])->values(),
            'severities' => collect(Severity::cases())->map(fn (Severity $severity) => ['value' => $severity->value, 'label' => $severity->label()])->values(),
            'timezones' => DateTimeZone::listIdentifiers(),
            'can' => ['manage' => $this->access->can($request->user(), $organizationId, 'alerting.manage')],
        ]);
    }

    public function store(RuleRequest $request, SaveRule $save): RedirectResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, 'alerting.manage');

        $save($organizationId, $request->validated());

        return to_route('alerting.rules.index');
    }

    public function update(RuleRequest $request, Rule $rule, SaveRule $save): RedirectResponse
    {
        $this->authorize('update', $rule);

        $save($rule->organization_id, $request->validated(), $rule);

        return to_route('alerting.rules.index');
    }

    public function destroy(Rule $rule, DeleteRule $delete): RedirectResponse
    {
        $this->authorize('delete', $rule);

        $delete($rule);

        return to_route('alerting.rules.index');
    }
}
