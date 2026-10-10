<?php

namespace Falak\Databases\Infrastructure\AgentRequests;

use Falak\Databases\Application\Actions\TakePitrBase;
use Falak\Databases\Domain\Models\PitrGap;
use Falak\Databases\Events\PitrAlert;
use Falak\Fleet\Contracts\AgentRequestHandler;
use Falak\Fleet\Contracts\Data\AgentCaller;
use Falak\Fleet\Contracts\Exceptions\AgentRequestRefused;
use Illuminate\Support\Facades\RateLimiter;

/**
 * pitr.gap: `falak-db binlog-rotate` found a break in the binlog chain. It is recorded (the timeline ends there), an
 * alert goes out, and a new base backup starts a new recoverable range (after a reset the agent restarts spooling
 * once that base is taken). A reset starts a new log epoch: binlogs numbered again never chain with the old ones.
 *
 * A gap already recorded and not covered by a base yet is not recorded again; an instance reports at most
 * databases.pitr.gaps_per_hour gaps.
 */
final class PitrGapReported implements AgentRequestHandler
{
    use ResolvesPitrInstance;

    public function __construct(private readonly TakePitrBase $base) {}

    public function handle(AgentCaller $caller, array $body): array
    {
        $instance = $this->instance($caller, $body);

        if (! RateLimiter::attempt("databases:pitr-gap:{$instance->id}", (int) config('databases.pitr.gaps_per_hour', 10), fn () => true, 3600)) {
            throw new AgentRequestRefused('too_many_gaps', 'Too many gaps reported for this instance; try again later.');
        }

        $details = [];
        $reset = false;

        foreach ((array) $body['gaps'] as $gap) {
            $attributes = [
                'database_instance_id' => $instance->id,
                'kind' => (string) $body['kind'],
                'gap' => (string) $gap['kind'],
                'from' => isset($gap['from']) ? mb_substr((string) $gap['from'], 0, 128) : null,
                'to' => isset($gap['to']) ? mb_substr((string) $gap['to'], 0, 128) : null,
            ];

            if (PitrGap::query()->where($attributes)->whereNull('resolved_at')->exists()) {
                continue;
            }

            PitrGap::query()->create([...$attributes, 'organization_id' => $instance->organization_id, 'detail' => mb_substr((string) $gap['detail'], 0, 1000), 'detected_at' => now()]);
            $details[] = (string) $gap['detail'];
            $reset = $reset || $gap['kind'] === 'reset';
        }

        if ($details === []) {
            return [];
        }

        if ($reset) {
            $instance->forceFill(['pitr_epoch' => $instance->pitr_epoch + 1])->save();
        }

        PitrAlert::dispatch(PitrAlert::GAP, $instance->organization_id, $instance->id, $instance->name, $instance->server_name,
            implode(' ', $details).' Recovery can\'t cross it; a new base backup was started.');
        ($this->base)($instance, 'gap');

        return [];
    }
}
