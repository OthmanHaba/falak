<?php

namespace Falak\Secrets\Application\Jobs;

use Falak\Alerting\Contracts\AlertConditions;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Falak\Secrets\Application\Scopes;
use Falak\Secrets\Domain\Models\Secret;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Hourly: secrets with a rotation policy whose current value is older than it (secrets.rotation_due), resolved once a
 * new version is written (or the policy removed).
 */
final class CheckSecretRotation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Unique while queued or running. */
    public int $uniqueFor = 3600;

    public function handle(AlertConditions $conditions, Scopes $scopes): void
    {
        Secret::query()->whereNotNull('rotation_days')->chunkById(500, function ($secrets) use ($conditions, $scopes) {
            Secret::withCurrentVersionDates($secrets);

            foreach ($secrets as $secret) {
                $due = $secret->rotationDueAt();
                $url = self::url($secret, $scopes);

                $conditions->observe($secret->organization_id, "secrets.rotation_due:{$secret->id}", $due !== null && $due->isPast(), fn () => new AlertData(
                    $secret->organization_id,
                    'secrets.rotation_due',
                    Severity::Warning,
                    "Secret {$secret->name} is due for rotation",
                    "Its policy asks for a new value every {$secret->rotation_days} days; the current one is older (due {$due?->format('Y-m-d')}). Set a new value, then redeploy what uses it.",
                    $url,
                    context: ['secret_id' => $secret->id, 'scope' => $scopes->label($secret->scope_type, $secret->scope_id)],
                    action: 'Rotate secret',
                ), fn () => new AlertData($secret->organization_id, 'secrets.rotation_due', Severity::Info, "Secret {$secret->name} was rotated", '', $url));
            }
        });

        // Secrets deleted, or whose policy was removed: resolved.
        $kept = Secret::query()->whereNotNull('rotation_days')->get(['id', 'organization_id'])->groupBy('organization_id');

        foreach (Secret::query()->distinct()->pluck('organization_id') as $organizationId) {
            $conditions->clearExcept((string) $organizationId, 'secrets.rotation_due:',
                ($kept[$organizationId] ?? collect())->map(fn (Secret $secret) => "secrets.rotation_due:{$secret->id}")->values()->all());
        }
    }

    public static function url(Secret $secret, Scopes $scopes): string
    {
        $project = $scopes->projectOf($secret->scope_type, $secret->scope_id);

        return $project !== null ? "/projects/{$project}/settings/secrets" : '/settings/secrets';
    }
}
