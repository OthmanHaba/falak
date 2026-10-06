<?php

namespace Falak\Secrets\Application;

use Falak\Deployments\Contracts\DeploymentTrigger;
use Falak\Processes\Contracts\ProcessControl;
use Falak\Secrets\Application\Actions\SetSecretValue;
use Falak\Secrets\Application\Providers\ValueFingerprint;
use Falak\Secrets\Contracts\Exceptions\SecretProviderUnavailable;
use Falak\Secrets\Domain\Enums\OnChange;
use Falak\Secrets\Domain\Enums\SecretKind;
use Falak\Secrets\Domain\Models\Secret;
use Falak\Secrets\Events\LinkedSecretChanged;
use Falak\Secrets\Infrastructure\ExternalSecretProviders;
use Throwable;

/**
 * The watch of a linked secret: ask its provider, compare with the fingerprint of the last value seen, and on a
 * change record a new version (same reference, the new value as its snapshot, "Changed upstream"), alert, and
 * restart or redeploy the services that use it.
 *
 * The first poll only records the baseline (and the current version's snapshot). Pinned secrets (rolled back
 * to a recorded value) are not watched until a new reference is saved. A provider failure is alerted by the
 * provider registry and retried at the next interval.
 */
final class LinkedSecretWatch
{
    public function __construct(
        private readonly ExternalSecretProviders $providers,
        private readonly SecretCipher $cipher,
        private readonly ValueFingerprint $fingerprint,
        private readonly SetSecretValue $set,
        private readonly SecretUsage $usage,
        private readonly DeploymentTrigger $deployments,
        private readonly ProcessControl $processes,
    ) {}

    /**
     * @return bool whether the value changed
     */
    public function poll(Secret $secret): bool
    {
        $secret->forceFill([
            'last_polled_at' => now(),
            'next_poll_at' => $secret->watch_minutes !== null ? now()->addMinutes(max(1, $secret->watch_minutes)) : null,
        ])->save();

        $version = $secret->currentVersion();

        if ($secret->kind !== SecretKind::Linked || $version === null || $version->disabled_at !== null || $version->pinned()) {
            return false;
        }

        $reference = $this->cipher->open($secret, $version);

        try {
            $value = $this->providers->refresh($reference, $secret->provider_id, $secret->organization_id);
        } catch (SecretProviderUnavailable) {
            return false;
        }

        if ($secret->value_hmac === null) {
            // Only while the polled version is still current and still has no baseline (no rollback or new
            // reference landed while the provider answered).
            $recorded = Secret::query()->whereKey($secret->id)->where('current_version', $version->version)->whereNull('value_hmac')
                ->update(['value_hmac' => $this->fingerprint->of($secret->organization_id, $value)]);

            if ($recorded === 1 && $version->snapshot === null) {
                $version->forceFill(['snapshot' => $this->cipher->sealSnapshot($secret, $version->version, $value)])->save();
            }

            return false;
        }

        if ($this->fingerprint->matches($secret->organization_id, $secret->value_hmac, $value)) {
            return false;
        }

        $new = $this->set->upstreamChange($secret, $version->version, $reference, $value, $this->fingerprint->of($secret->organization_id, $value));

        if ($new === null) {
            return false;
        }

        $sites = $secret->on_change === OnChange::None ? [] : $this->act($secret);

        LinkedSecretChanged::dispatch($secret->organization_id, $secret->id, $secret->name, $new->version, $secret->on_change->value, $sites);

        return true;
    }

    /**
     * @return list<string> the sites restarted or redeployed
     */
    private function act(Secret $secret): array
    {
        $sites = [];

        foreach ($this->usage->of([$secret])[$secret->id] ?? [] as $user) {
            try {
                if ($secret->on_change === OnChange::Redeploy) {
                    $this->deployments->deploy($user['site_id'], null, message: "Secret {$secret->name} changed upstream", author: 'Falak');
                } else {
                    $this->processes->restartForSite($user['site_id'], newRelease: false);
                }

                $sites[] = $user['site_id'];
            } catch (Throwable $e) {
                // One service that can't be redeployed (no source yet, …) must not stop the others.
                report($e);
            }
        }

        return $sites;
    }
}
