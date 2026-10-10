<?php

namespace Falak\Alerting\Application;

use Falak\Alerting\Contracts\AlertTypes;
use Falak\Alerting\Contracts\Severity;
use Falak\Alerting\Domain\Models\Channel;
use Falak\Alerting\Domain\Models\Rule;
use Falak\Alerting\Domain\Models\RulePack;
use Falak\Identity\Contracts\OrganizationDirectory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The default rule pack: one editable rule per area (alert type group) whose types reach Warning, matching the area's
 * type prefixes ("databases.*", "pitr.*") from Warning up and routed to the organization's default channel — none: in-app
 * notifications only, until a channel is added ({@see attachDefaultChannel()}).
 *
 * Applied when an organization is created, by the migration that introduced it, and daily (alerting:default-rules), so
 * a group a module registers later (dr.*) joins the pack. alerting_rule_packs records every area and pattern applied:
 * a rule the user deleted, or a pattern removed from it, is never brought back.
 */
final class DefaultRulePack
{
    public const MIN_SEVERITY = Severity::Warning;

    public function __construct(
        private readonly AlertTypes $types,
        private readonly OrganizationDirectory $organizations,
    ) {}

    /**
     * The pack's areas: pack key => [group, patterns].
     *
     * @return array<string, array{group: string, patterns: list<string>}>
     */
    public function areas(): array
    {
        $areas = [];

        foreach ($this->types->all() as $type) {
            if (! $type['severity']->atLeast(self::MIN_SEVERITY)) {
                continue;
            }

            $key = self::key($type['group']);
            $areas[$key]['group'] = $type['group'];
            $areas[$key]['patterns'][] = Str::before($type['type'], '.').'.*';
        }

        return array_map(fn (array $area) => ['group' => $area['group'], 'patterns' => array_values(array_unique($area['patterns']))], $areas);
    }

    public static function key(string $group): string
    {
        return 'area:'.Str::slug($group);
    }

    public function applyAll(): void
    {
        foreach ($this->organizations->all() as $organization) {
            $this->apply($organization->id);
        }
    }

    /**
     * @return int rules created or extended
     */
    public function apply(string $organizationId): int
    {
        $changed = 0;
        $default = Channel::query()->where('organization_id', $organizationId)->where('is_default', true)->first();

        foreach ($this->areas() as $key => $area) {
            try {
                $changed += DB::transaction(fn () => $this->applyArea($organizationId, $key, $area['group'], $area['patterns'], $default));
            } catch (UniqueConstraintViolationException) {
                // Applied concurrently.
            }
        }

        return $changed;
    }

    /**
     * Routes the pack's rules that have no channel yet (in-app only) to $channel: the organization's first channel.
     */
    public function attachDefaultChannel(Channel $channel): void
    {
        Rule::query()->where('organization_id', $channel->organization_id)->whereNotNull('pack_key')->whereDoesntHave('channels')->get()
            ->each(fn (Rule $rule) => $rule->channels()->syncWithoutDetaching([$channel->id]));
    }

    /**
     * @param  list<string>  $patterns
     */
    private function applyArea(string $organizationId, string $key, string $group, array $patterns, ?Channel $default): int
    {
        $pack = RulePack::query()->where('organization_id', $organizationId)->where('pack_key', $key)->lockForUpdate()->first();

        if ($pack === null) {
            $rule = Rule::query()->create([
                'organization_id' => $organizationId,
                'pack_key' => $key,
                'name' => $group,
                'event_types' => $patterns,
                'min_severity' => self::MIN_SEVERITY,
                'enabled' => true,
            ]);

            if ($default !== null) {
                $rule->channels()->sync([$default->id]);
            }

            RulePack::query()->create(['organization_id' => $organizationId, 'pack_key' => $key, 'rule_id' => $rule->id, 'patterns' => $patterns, 'applied_at' => now()]);

            return 1;
        }

        $new = array_values(array_diff($patterns, $pack->patterns));

        if ($new === []) {
            return 0;
        }

        $rule = $pack->rule_id ? Rule::query()->where('organization_id', $organizationId)->find($pack->rule_id) : null;
        $rule?->forceFill(['event_types' => array_values(array_unique([...$rule->event_types, ...$new]))])->save();
        $pack->forceFill(['patterns' => [...$pack->patterns, ...$new], 'applied_at' => now()])->save();

        return $rule ? 1 : 0;
    }
}
