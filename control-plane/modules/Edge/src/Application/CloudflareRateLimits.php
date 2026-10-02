<?php

namespace Kiln\Edge\Application;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Kiln\Edge\Domain\Models\CloudflareZone;
use Kiln\Edge\Domain\Models\Domain;
use Kiln\Edge\Infrastructure\Cloudflare\CloudflareApi;
use Kiln\Edge\Infrastructure\Cloudflare\CloudflareError;
use Kiln\Identity\Contracts\AuditLog;

/**
 * Rate limits per domain through Cloudflare (docs/CLOUDFLARE.md → Rate limits). Kiln's edge is stock Caddy, which has
 * no rate limiting, so a rule only applies to names Cloudflare proxies. An organization's rules (description
 * "kiln:ratelimit:<organization id>:<domain id> <name>") are rebuilt from its domains in the zone's http_ratelimit
 * entry point; every other rule (the zone's own, another organization's or another Kiln install's) stays as it is, in
 * its place. Rules of the first format ("kiln:ratelimit:<domain id> <name>") are recognised by their domain.
 *
 * What a plan allows (Cloudflare's rate limiting rules): Free one rule per zone, matching the path only (no host), a
 * 10-second window and a 10-second block; Pro 2 rules, host + path, windows up to a minute, blocks up to an hour;
 * Business 5, up to 10 minutes / a day; Enterprise 100. A managed challenge has no duration below Enterprise: Cloudflare
 * challenges each request over the limit (mitigation_timeout 0, "request throttling"), and a passed challenge resets
 * the visitor's count.
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

    /** Rule fields Cloudflare sets itself (not sent back when re-writing the entry point). */
    private const READ_ONLY = ['version', 'last_updated'];

    public function __construct(private readonly AuditLog $audit) {}

    /**
     * What the zone's plan allows, for the UI and validation.
     *
     * @return array{plan: string, rules: int, host: bool, periods: list<int>, timeouts: list<int>, challenge_timeout: bool, note: ?string}
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
            // Whether a managed challenge takes a duration (Enterprise); otherwise it applies per request (timeout 0).
            'challenge_timeout' => $plan === 'enterprise',
            'note' => $plan === 'free'
                ? 'Free plan: one rule per zone, 10-second window, 10-second block (a managed challenge applies per request); it matches the path only, so it applies to every proxied name in the zone.'
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
            $rule = $this->validate($rule, self::limits($this->plan($zone)), $zone);
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
        // The entry point is read, then replaced as a whole: one sync per Cloudflare zone at a time (organizations of
        // this install sharing a zone included).
        try {
            Cache::lock('edge:cloudflare-ratelimit:'.$zone->zone_id, 120)->block((int) config('edge.cloudflare_lock_wait', 15), fn () => $this->syncLocked($zone));
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['rate_limit' => "Another rate limit change for {$zone->name} is in progress. Try again in a moment."]);
        }
    }

    /** @throws CloudflareError|ValidationException */
    private function syncLocked(CloudflareZone $zone): void
    {
        $api = CloudflareApi::with($zone->credential->api_token);
        $limits = self::limits($this->plan($zone));
        $entrypoint = $api->ruleset($zone->zone_id, self::PHASE);
        $all = array_values((array) ($entrypoint['rules'] ?? []));
        $legacy = $this->legacyDomainIds($zone->organization_id, $all);
        $theirs = array_values(array_filter($all, fn (array $rule) => ! $this->isOurs($zone->organization_id, (string) ($rule['description'] ?? ''), $legacy)));
        $hadOurs = count($all) !== count($theirs);

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
        // Other rules go back as Cloudflare returned them (ref, logging, …), without the fields it sets itself.
        $theirs = array_map(fn (array $rule) => array_diff_key($rule, array_flip(self::READ_ONLY)), $theirs);
        $api->putRuleset($zone->zone_id, self::PHASE, [...$theirs, ...$ours]);
        $zone->forceFill(['rate_limited' => $ours !== []])->save();
    }

    /**
     * Re-syncs the zone covering $host after a domain was removed or its proxy switched, when Kiln has rules there (or
     * with $force); failures are logged, not thrown.
     */
    public function resyncFor(string $organizationId, string $host, bool $force = false): void
    {
        $zone = CloudflareZone::forHost($organizationId, $host);

        // $force: the domain has a rule of its own, which may not be in the zone yet (proxy switched back on).
        if ($zone === null || (! $zone->rate_limited && ! $force)) {
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
     * @param  array{plan: string, rules: int, host: bool, periods: list<int>, timeouts: list<int>, challenge_timeout: bool, note: ?string}  $limits
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
            'description' => self::PREFIX.$domain->organization_id.':'.$domain->id.' '.$domain->name,
            'expression' => '('.implode(' and ', $conditions).')',
            'action' => (string) $rule['action'],
            'ratelimit' => [
                'characteristics' => ['cf.colo.id', 'ip.src'],
                'period' => (int) $rule['period'],
                'requests_per_period' => (int) $rule['requests'],
                // Below Enterprise a challenge has no duration: Cloudflare requires 0 (request throttling).
                'mitigation_timeout' => $this->challengeOnly($rule, $limits) ? 0 : (int) $rule['timeout'],
            ],
            'enabled' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array{plan: string, rules: int, host: bool, periods: list<int>, timeouts: list<int>, challenge_timeout: bool, note: ?string}  $limits
     * @return array{path: ?string, requests: int, period: int, action: string, timeout: int}
     *
     * @throws ValidationException
     */
    private function validate(array $rule, array $limits, CloudflareZone $zone): array
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
        $challengeOnly = $this->challengeOnly($rule, $limits);
        if (! $challengeOnly && ! in_array($rule['timeout'] ?? null, $limits['timeouts'], true)) {
            $errors['timeout'] = $limits['plan'] === 'free'
                ? 'Free plan: offenders are blocked for 10 seconds.'
                : 'Pick a duration the '.$limits['plan'].' plan allows: '.implode(', ', $limits['timeouts']).' s.';
        }
        if (! in_array($rule['action'] ?? null, self::ACTIONS, true)) {
            $errors['action'] = 'Block or managed challenge.';
        }
        if ($path === '' && ! $limits['host'] && ($panel = $this->panelHost()) !== null && $zone->covers($panel)) {
            // Free rules have no host: without a path this one would rate limit every request of the zone, the panel's too.
            $errors['path'] = "Free plan: the rule applies to every proxied name of {$zone->name}, this panel ({$panel}) included. Give it a path.";
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['path' => $path === '' ? null : $path, 'requests' => (int) $rule['requests'], 'period' => (int) $rule['period'], 'action' => (string) $rule['action'], 'timeout' => $challengeOnly ? 0 : (int) $rule['timeout']];
    }

    /**
     * A managed challenge on a plan where it takes no duration (below Enterprise): it challenges each request over the limit.
     *
     * @param  array<string, mixed>  $rule
     * @param  array{challenge_timeout: bool}  $limits
     */
    private function challengeOnly(array $rule, array $limits): bool
    {
        return ($rule['action'] ?? null) === 'managed_challenge' && ! $limits['challenge_timeout'];
    }

    /**
     * The Kiln rule of the zone that applies to $domain although it is another domain's: rules on plans without a host
     * condition (Free) match every proxied name of the zone. Null when there is none or $domain isn't proxied.
     *
     * @return ?array{domain: string, path: ?string}
     */
    public function zoneWideRule(Domain $domain, ?CloudflareZone $zone = null): ?array
    {
        $zone ??= CloudflareZone::forHost($domain->organization_id, $domain->name);

        if ($zone === null || self::limits($zone->plan)['host'] || ! $this->proxied($domain, $zone)) {
            return null;
        }

        $owner = Domain::query()->where('organization_id', $zone->organization_id)->whereNotNull('cloudflare_rate_limit')->whereKeyNot($domain->id)->orderBy('name')->get()
            ->first(fn (Domain $d) => $zone->covers($d->name) && ! $d->isWildcard() && $this->proxied($d, $zone));

        return $owner === null ? null : ['domain' => $owner->name, 'path' => $owner->cloudflare_rate_limit['path'] ?? null];
    }

    /** The panel's own host (config app.url), lowercased. */
    private function panelHost(): ?string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /**
     * Whether a rule description is this organization's: "kiln:ratelimit:<organization id>:…", or the first format
     * "kiln:ratelimit:<domain id> …" for one of its domains ($legacy).
     *
     * @param  array<string, true>  $legacy
     */
    private function isOurs(string $organizationId, string $description, array $legacy): bool
    {
        if (str_starts_with($description, self::PREFIX.$organizationId.':')) {
            return true;
        }

        return preg_match('/^'.preg_quote(self::PREFIX, '/').'([0-9a-z]{26}) /', $description, $m) === 1 && isset($legacy[$m[1]]);
    }

    /**
     * Domain ids in first-format rules that belong to this organization.
     *
     * @param  list<array<string, mixed>>  $rules
     * @return array<string, true>
     */
    private function legacyDomainIds(string $organizationId, array $rules): array
    {
        $ids = [];
        foreach ($rules as $rule) {
            if (preg_match('/^'.preg_quote(self::PREFIX, '/').'([0-9a-z]{26}) /', (string) ($rule['description'] ?? ''), $m) === 1) {
                $ids[] = $m[1];
            }
        }

        return $ids === [] ? [] : Domain::query()->where('organization_id', $organizationId)->whereKey($ids)->pluck('id')->mapWithKeys(fn (string $id) => [$id => true])->all();
    }

    private function proxied(Domain $domain, CloudflareZone $zone): bool
    {
        return $domain->cloudflare_proxied ?? $zone->proxied;
    }
}
