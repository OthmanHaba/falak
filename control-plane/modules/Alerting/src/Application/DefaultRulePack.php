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
        $groups = [];

        foreach ($this->types->all() as $type) {
            if ($type['severity']->atLeast(self::MIN_SEVERITY)) {
                $groups[$type['group']][] = Str::before($type['type'], '.');
            }
        }

        $areas = [];

        foreach ($groups as $group => $prefixes) {
            $areas[self::key($prefixes)] = ['group' => $group, 'patterns' => array_values(array_unique(array_map(fn (string $prefix) => "{$prefix}.*", $prefixes)))];
        }

        return $areas;
    }

    /**
     * An area's machine key: its main type prefix (the one most of its types share; ties: alphabetical), so renaming a
     * group's label keeps the area, e.g. "area:databases", "area:dr".
     *
     * @param  list<string>  $prefixes  one per type
     */
    public static function key(array $prefixes): string
    {
        $counts = array_count_values($prefixes);
        uksort($counts, fn (string $a, string $b) => [$counts[$b], $a] <=> [$counts[$a], $b]);

        return 'area:'.array_key_first($counts);
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
     * Routes the pack's rules that have no channel yet (in-app only) and nobody edited to $channel: the organization's
     * default channel.
     */
    public function attachDefaultChannel(Channel $channel): void
    {
        Rule::query()->where('organization_id', $channel->organization_id)->whereNotNull('pack_key')->where('user_modified', false)->whereDoesntHave('channels')->get()
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
