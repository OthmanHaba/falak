<?php

namespace Kiln\Edge\Application;

use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Kiln\Edge\Domain\Models\CloudflareZone;
use Kiln\Edge\Domain\Models\Domain;
use Kiln\Edge\Infrastructure\Cloudflare\CloudflareApi;
use Kiln\Edge\Infrastructure\Cloudflare\CloudflareError;
use Kiln\Identity\Contracts\AuditLog;

/**
 * Rate limits per domain through Cloudflare (docs/CLOUDFLARE.md → Rate limits). Kiln's edge is stock Caddy, which has
 * no rate limiting, so a rule only applies to names Cloudflare proxies. Kiln's rules (description
 * "kiln:ratelimit:<domain id> <name>") are rebuilt from its domains in the zone's http_ratelimit entry point; every
 * other rule stays as it is, in its place.
 *
 * What a plan allows (Cloudflare's rate limiting rules): Free one rule per zone, matching the path only (no host), a
 * 10-second window and a 10-second block; Pro 2 rules, host + path, windows up to a minute, blocks up to an hour;
 * Business 5, up to 10 minutes / a day; Enterprise 100.
 */
final class CloudflareRateLimits
{
    public const PHASE = 'http_ratelimit';

    public const ACTIONS = ['block', 'managed_challenge'];

    /** Values Cloudflare accepts for periods and mitigation timeouts (seconds); plans allow those up to their maximum. */
    private const VALUES = [10, 15, 20, 30, 40, 45, 60, 90, 120, 180, 240, 300, 480, 600, 900, 1200, 1800, 2400, 3600, 65535, 86400];

    /** @var array<string, array{rules: int, host: bool, period: int, timeout: int}> */
    private const PLANS = [
        'free' => ['rules' => 1, 'host' => false, 'period' => 10, 'timeout' => 10],
        'pro' => ['rules' => 2, 'host' => true, 'period' => 60, 'timeout' => 3600],
        'business' => ['rules' => 5, 'host' => true, 'period' => 600, 'timeout' => 86400],
        'enterprise' => ['rules' => 100, 'host' => true, 'period' => 65535, 'timeout' => 86400],
    ];

    private const PREFIX = 'kiln:ratelimit:';

    public function __construct(private readonly AuditLog $audit) {}

    /**
     * What the zone's plan allows, for the UI and validation.
     *
     * @return array{plan: string, rules: int, host: bool, periods: list<int>, timeouts: list<int>, note: ?string}
     */
    public static function limits(?string $plan): array
    {
        $plan = array_key_exists((string) $plan, self::PLANS) ? (string) $plan : 'free';
        $limits = self::PLANS[$plan];

        return [
            'plan' => $plan,
            'rules' => $limits['rules'],
            'host' => $limits['host'],
            'periods' => array_values(array_filter(self::VALUES, fn (int $v) => $v <= $limits['period'])),
            // 65535 is a period value only.
            'timeouts' => array_values(array_filter(self::VALUES, fn (int $v) => $v <= $limits['timeout'] && $v !== 65535)),
            'note' => $plan === 'free'
                ? 'Free plan: one rule per zone, 10-second window, 10-second block; it matches the path only, so it applies to every proxied name in the zone.'
                : null,
        ];
    }

    /** The zone's plan, read from Cloudflare when unknown (or when $refresh). */
    public function plan(CloudflareZone $zone, bool $refresh = false): string
    {
        if ($zone->plan !== null && ! $refresh) {
            return $zone->plan;
        }

        try {
            $plan = CloudflareApi::with($zone->credential->api_token)->plan($zone->zone_id);
            $zone->forceFill(['plan' => $plan])->save();

            return $plan;
        } catch (CloudflareError $e) {
            Log::warning('Cloudflare zone plan unknown, assuming Free', ['zone' => $zone->name, 'error' => $e->getMessage()]);

            return $zone->plan ?? 'free';
        }
    }

    /**
     * Sets (or with null removes) the rate limit of a domain. Saved only once Cloudflare accepted the zone's rules.
     *
     * @param  ?array{path?: ?string, requests: int, period: int, action: string, timeout: int}  $rule
     *
     * @throws ValidationException
     * @throws CloudflareError
     */
    public function set(Domain $domain, ?array $rule, ?string $actorId = null): void
    {
        $zone = CloudflareZone::forHost($domain->organization_id, $domain->name)
            ?? throw ValidationException::withMessages(['rate_limit' => 'This domain is not in a Cloudflare zone Kiln manages.']);

        if ($rule !== null) {
            if ($domain->isWildcard()) {
                throw ValidationException::withMessages(['rate_limit' => 'Wildcard domains can’t have a rate limit.']);
            }
            if (! $this->proxied($domain, $zone)) {
                throw ValidationException::withMessages(['rate_limit' => 'Rate limits need the Cloudflare proxy (orange cloud): Kiln’s edge (Caddy) has no rate limiting.']);
            }
            $rule = $this->validate($rule, self::limits($this->plan($zone)));
        }

        $previous = $domain->cloudflare_rate_limit;
        $domain->forceFill(['cloudflare_rate_limit' => $rule])->save();

        try {
            $this->sync($zone);
        } catch (CloudflareError|ValidationException $e) {
            $domain->forceFill(['cloudflare_rate_limit' => $previous])->save();

            throw $e;
        }

        $this->audit->record('edge.cloudflare_rate_limit', 'site', $domain->site_id, ['domain' => $domain->name, 'rule' => $rule], $domain->organization_id, $actorId);
    }

    /**
     * Rebuilds Kiln's rules of a zone (domains with a rule that are proxied and still exist); the zone's other rules
     * stay. Throws when the plan has no room (Free: one rule, and the zone may already have its own).
     *
     * @throws CloudflareError|ValidationException
     */
    public function sync(CloudflareZone $zone): void
    {
        $api = CloudflareApi::with($zone->credential->api_token);
        $limits = self::limits($this->plan($zone));
        $entrypoint = $api->ruleset($zone->zone_id, self::PHASE);
        $theirs = array_values(array_filter((array) ($entrypoint['rules'] ?? []), fn (array $rule) => ! str_starts_with((string) ($rule['description'] ?? ''), self::PREFIX)));
        $hadOurs = count((array) ($entrypoint['rules'] ?? [])) !== count($theirs);

        $domains = Domain::query()->where('organization_id', $zone->organization_id)->whereNotNull('cloudflare_rate_limit')->orderBy('name')->get()
            ->filter(fn (Domain $d) => $zone->covers($d->name) && ! $d->isWildcard() && $this->proxied($d, $zone))->values();

        if ($domains->isEmpty() && ! $hadOurs) {
            return; // nothing of Kiln's to write or remove: no call (and no permission needed)
        }

        if (count($theirs) + $domains->count() > $limits['rules']) {
            throw ValidationException::withMessages(['rate_limit' => $limits['plan'] === 'free'
                ? 'Free plan: one rate limit rule per zone'.($theirs !== [] ? ', and '.$zone->name.' already has one of its own.' : '; another domain of '.$zone->name.' already uses it.')
                : "The {$limits['plan']} plan allows {$limits['rules']} rate limit rules in {$zone->name}; it would have ".(count($theirs) + $domains->count()).'.']);
        }

        $ours = $domains->map(fn (Domain $domain) => $this->rule($domain, (array) $domain->cloudflare_rate_limit, $limits))->all();
        $rules = array_map(fn (array $rule) => array_intersect_key($rule, array_flip(['id', 'description', 'expression', 'action', 'action_parameters', 'ratelimit', 'enabled'])), [...$theirs, ...$ours]);
        $api->putRuleset($zone->zone_id, self::PHASE, $rules);
        $zone->forceFill(['rate_limited' => $ours !== []])->save();
    }

    /**
     * Re-syncs the zone covering $host after a domain was removed or its proxy switched, when Kiln has rules there;
     * failures are logged, not thrown.
     */
    public function resyncFor(string $organizationId, string $host): void
    {
        $zone = CloudflareZone::forHost($organizationId, $host);

        if ($zone === null || ! $zone->rate_limited) {
            return;
        }

        try {
            $this->sync($zone);
        } catch (CloudflareError|ValidationException $e) {
            Log::warning('Cloudflare rate limits not synced', ['zone' => $zone->name, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array{plan: string, rules: int, host: bool, periods: list<int>, timeouts: list<int>, note: ?string}  $limits
     * @return array<string, mixed>
     */
    private function rule(Domain $domain, array $rule, array $limits): array
    {
        $path = isset($rule['path']) && $rule['path'] !== '' ? (string) $rule['path'] : null;
        $conditions = [];

        if ($limits['host']) {
            $hosts = implode(' ', array_map(fn (string $h) => '"'.$h.'"', $domain->hosts()));
            $conditions[] = "http.host in {{$hosts}}";
        }
        // Free matches the path only; without a path the rule covers every request of the zone.
        $conditions[] = 'starts_with(http.request.uri.path, "'.($path ?? '/').'")';

        return [
            'description' => self::PREFIX.$domain->id.' '.$domain->name,
            'expression' => '('.implode(' and ', $conditions).')',
            'action' => (string) $rule['action'],
            'ratelimit' => [
                'characteristics' => ['cf.colo.id', 'ip.src'],
                'period' => (int) $rule['period'],
                'requests_per_period' => (int) $rule['requests'],
                'mitigation_timeout' => (int) $rule['timeout'],
            ],
            'enabled' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array{plan: string, rules: int, host: bool, periods: list<int>, timeouts: list<int>, note: ?string}  $limits
     * @return array{path: ?string, requests: int, period: int, action: string, timeout: int}
     *
     * @throws ValidationException
     */
    private function validate(array $rule, array $limits): array
    {
        $path = isset($rule['path']) ? trim((string) $rule['path']) : '';
        $errors = [];

        if ($path !== '' && (! str_starts_with($path, '/') || strlen($path) > 200 || preg_match('/["\\\\\s]/', $path))) {
            $errors['path'] = 'A path starts with / and has no spaces, quotes or backslashes (at most 200 characters).';
        }
        if (! is_int($rule['requests'] ?? null) || $rule['requests'] < 1 || $rule['requests'] > 1_000_000) {
            $errors['requests'] = 'Requests: a whole number from 1 to 1,000,000.';
        }
        if (! in_array($rule['period'] ?? null, $limits['periods'], true)) {
            $errors['period'] = $limits['plan'] === 'free'
                ? 'Free plan: the window is 10 seconds.'
                : 'Pick a window the '.$limits['plan'].' plan allows: '.implode(', ', $limits['periods']).' s.';
        }
        if (! in_array($rule['timeout'] ?? null, $limits['timeouts'], true)) {
            $errors['timeout'] = $limits['plan'] === 'free'
                ? 'Free plan: offenders are blocked for 10 seconds.'
                : 'Pick a duration the '.$limits['plan'].' plan allows: '.implode(', ', $limits['timeouts']).' s.';
        }
        if (! in_array($rule['action'] ?? null, self::ACTIONS, true)) {
            $errors['action'] = 'Block or managed challenge.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['path' => $path === '' ? null : $path, 'requests' => (int) $rule['requests'], 'period' => (int) $rule['period'], 'action' => (string) $rule['action'], 'timeout' => (int) $rule['timeout']];
    }

    private function proxied(Domain $domain, CloudflareZone $zone): bool
    {
        return $domain->cloudflare_proxied ?? $zone->proxied;
    }
}
