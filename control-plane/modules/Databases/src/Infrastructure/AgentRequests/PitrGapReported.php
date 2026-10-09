<?php

namespace Falak\Databases\Infrastructure\AgentRequests;

use Falak\Databases\Application\Actions\TakePitrBase;
use Falak\Databases\Domain\Models\PitrGap;
use Falak\Databases\Events\PitrAlert;
use Falak\Fleet\Contracts\AgentRequestHandler;
use Falak\Fleet\Contracts\Data\AgentCaller;

/**
 * pitr.gap: `falak-db binlog-rotate` found a break in the binlog chain. It is recorded (the timeline ends there), an
 * alert goes out, and a new base backup starts a new recoverable range (after a reset the agent restarts spooling
 * once that base is taken).
 */
final class PitrGapReported implements AgentRequestHandler
{
    use ResolvesPitrInstance;

    public function __construct(private readonly TakePitrBase $base) {}

    public function handle(AgentCaller $caller, array $body): array
    {
        $instance = $this->instance($caller, $body);
        $details = [];

        foreach ((array) $body['gaps'] as $gap) {
            PitrGap::query()->create([
                'organization_id' => $instance->organization_id,
                'database_instance_id' => $instance->id,
                'kind' => (string) $body['kind'],
                'gap' => (string) $gap['kind'],
                'from' => isset($gap['from']) ? mb_substr((string) $gap['from'], 0, 128) : null,
                'to' => isset($gap['to']) ? mb_substr((string) $gap['to'], 0, 128) : null,
                'detail' => mb_substr((string) $gap['detail'], 0, 1000),
                'detected_at' => now(),
            ]);
            $details[] = (string) $gap['detail'];
        }

        PitrAlert::dispatch(PitrAlert::GAP, $instance->organization_id, $instance->id, $instance->name, $instance->server_name,
            implode(' ', $details).' Recovery can\'t cross it; a new base backup was started.');
        ($this->base)($instance, 'gap');

        return [];
    }
}
