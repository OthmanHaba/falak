<?php

namespace Falak\Edge\Application\Jobs;

use Carbon\CarbonImmutable;
use Falak\Alerting\Contracts\AlertConditions;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Falak\Edge\Application\EdgeChanges;
use Falak\Edge\Contracts\TlsMode;
use Falak\Edge\Domain\Models\Certificate;
use Falak\Edge\Domain\Models\Domain;
use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Servers\Contracts\ServerDirectory;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

/**
 * Hourly: certificates expiring within 14, 7 and 1 days (edge.certificate_expiring; 14: warning, 7 and 1: critical).
 *
 *  - ACME (tls_mode auto / dns): the expiry the servers serving the domain's site report in their facts
 *    (tls_certificates) for its name — or a wildcard covering it (Caddy stores "*.x" as "wildcard_.x") — the earliest
 *    when several do. Servers that no longer serve the site are ignored: their storage keeps old certificates. The
 *    edge renews 30 days ahead, so a certificate this close to expiry means renewal keeps failing (DNS no longer points
 *    at the server, a blocked port 80, rate limits).
 *  - Uploaded (custom): the certificate's not_after, while a domain uses it. Nobody renews those but the user.
 *
 * Each stage alerts once; a renewal resolves them (one recovery, from the 14-day stage). Unique while queued or running.
 */
final class CheckCertificateExpiry implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    /** days => severity */
    public const STAGES = [14 => Severity::Warning, 7 => Severity::Critical, 1 => Severity::Critical];

    public function handle(AgentDirectory $agents, ServerDirectory $servers, AlertConditions $conditions, EdgeChanges $edge): void
    {
        $now = CarbonImmutable::now();

        foreach (Domain::query()->distinct()->pluck('organization_id') as $organizationId) {
            $domains = Domain::query()->with('certificate')->where('organization_id', $organizationId)->get();
            $expiries = $this->acmeExpiries((string) $organizationId, $agents, $servers);
            $keep = [];

            $serving = [];

            foreach ($domains->whereIn('tls_mode', [TlsMode::Auto, TlsMode::Dns]) as $domain) {
                $serving[$domain->site_id] ??= $edge->serversFor($domain->site_id);
                $notAfter = self::expiryFor(strtolower($domain->name), array_intersect_key($expiries, array_flip($serving[$domain->site_id])));

                if ($notAfter !== null) {
                    $keep = [...$keep, ...$this->stages((string) $organizationId, "domain:{$domain->id}", $domain->name, $notAfter, $now, $domain->site_id, false, $conditions)];
                }
            }

            /** @var Collection<int, Certificate> $uploaded */
            $uploaded = $domains->where('tls_mode', TlsMode::Custom)->pluck('certificate')->filter()->unique('id');

            foreach ($uploaded as $certificate) {
                if ($certificate->not_after !== null) {
                    $site = $domains->firstWhere('certificate_id', $certificate->id)?->site_id;
                    $keep = [...$keep, ...$this->stages((string) $organizationId, "cert:{$certificate->id}", implode(', ', array_slice($certificate->domains, 0, 3)),
                        $certificate->not_after->toImmutable(), $now, $site, true, $conditions)];
                }
            }

            // Domains removed, switched to another TLS mode or no longer served.
            $conditions->clearExcept((string) $organizationId, 'edge.cert_expiry:', $keep);
        }
    }

    /**
     * @return list<string> the condition keys observed
     */
    private function stages(string $organizationId, string $subject, string $names, CarbonImmutable $notAfter, CarbonImmutable $now, ?string $siteId, bool $uploaded, AlertConditions $conditions): array
    {
        $daysLeft = $now->diffInSeconds($notAfter, false) / 86400;
        $url = $siteId ? "/sites/{$siteId}/domains" : '/domains';
        $keys = [];

        foreach (self::STAGES as $days => $severity) {
            $key = "edge.cert_expiry:{$subject}:{$days}";
            $keys[] = $key;
            $left = $daysLeft <= 0 ? 'has expired' : 'expires in '.($daysLeft < 1 ? 'less than a day' : (int) floor($daysLeft).' day'.((int) floor($daysLeft) === 1 ? '' : 's'));

            $conditions->observe($organizationId, $key, $daysLeft <= $days, fn () => new AlertData(
                $organizationId,
                'edge.certificate_expiring',
                $severity,
                "Certificate for {$names} {$left}",
                $uploaded
                    ? "The uploaded certificate is valid until {$notAfter->format('Y-m-d H:i T')}. Upload a renewed one (or switch the domain to automatic TLS)."
                    : "Valid until {$notAfter->format('Y-m-d H:i T')}. Falak renews certificates 30 days ahead, so renewal keeps failing: check that the domain's DNS still points at the server and that port 80/443 is reachable.",
                $url,
                context: array_filter(['site_id' => $siteId, 'not_after' => $notAfter->toIso8601String(), 'days_left' => (int) floor(max(0, $daysLeft))], fn ($v) => $v !== null),
                action: $uploaded ? 'Upload renewed certificate' : 'Inspect certificate',
            ), $days === 14 ? fn () => new AlertData($organizationId, 'edge.certificate_expiring', Severity::Info, "Certificate for {$names} renewed",
                "Now valid until {$notAfter->format('Y-m-d')}.", $url) : null, announceRecovery: $days === 14);
        }

        return $keys;
    }

    /**
     * The earliest expiry among the serving servers' certificates for $host: its own name, else a wildcard covering it.
     *
     * @param  array<string, array<string, CarbonImmutable>>  $byServer  server id => name => not_after
     */
    public static function expiryFor(string $host, array $byServer): ?CarbonImmutable
    {
        $wildcard = str_contains($host, '.') && ! str_starts_with($host, '*.') ? '*.'.substr($host, strpos($host, '.') + 1) : null;
        $earliest = null;

        foreach ($byServer as $names) {
            $at = $names[$host] ?? ($wildcard !== null ? ($names[$wildcard] ?? null) : null);

            if ($at !== null && ($earliest === null || $at < $earliest)) {
                $earliest = $at;
            }
        }

        return $earliest;
    }

    /** Caddy's storage name for a certificate ("wildcard_.example.com" holds "*.example.com"). */
    public static function certificateName(string $stored): string
    {
        $name = strtolower($stored);

        return str_starts_with($name, 'wildcard_.') ? '*.'.substr($name, strlen('wildcard_.')) : $name;
    }

    /**
     * The ACME certificates the organization's servers report (facts tls_certificates).
     *
     * @return array<string, array<string, CarbonImmutable>> server id => name => not_after
     */
    private function acmeExpiries(string $organizationId, AgentDirectory $agents, ServerDirectory $servers): array
    {
        $ids = array_map(fn ($server) => $server->id, $servers->forOrganization($organizationId, activeOnly: true));
        $out = [];

        foreach ($ids === [] ? [] : $agents->forServers($ids) as $serverId => $agent) {
            foreach ((array) ($agent->facts['tls_certificates'] ?? []) as $cert) {
                if (is_array($cert) && is_string($cert['name'] ?? null) && is_string($cert['not_after'] ?? null)) {
                    $out[$serverId][self::certificateName($cert['name'])] = CarbonImmutable::parse($cert['not_after']);
                }
            }
        }

        return $out;
    }
}
