<?php

namespace Falak\Databases\Infrastructure\AgentRequests;

use Carbon\CarbonImmutable;
use Falak\Databases\Domain\Models\PitrSegment;
use Falak\Fleet\Contracts\AgentRequestHandler;
use Falak\Fleet\Contracts\Data\AgentCaller;

/**
 * pitr.shipped: segments the agent uploaded. A segment is acknowledged (and only then deleted from the spool by the
 * agent) when it is one this instance asked a URL for, with the same name and content; its stored size and SHA-256 and
 * its end time are recorded.
 */
final class PitrShipped implements AgentRequestHandler
{
    use ResolvesPitrInstance;

    public function handle(AgentCaller $caller, array $body): array
    {
        $instance = $this->instance($caller, $body);
        $acknowledged = [];

        foreach ((array) $body['segments'] as $item) {
            $segment = PitrSegment::query()->whereKey(strtolower((string) $item['id']))
                ->where('database_instance_id', $instance->id)->where('kind', (string) $body['kind'])
                ->where('name', (string) $item['name'])->where('plaintext_sha256', strtolower((string) $item['plaintext_sha256']))
                ->first();

            if ($segment === null) {
                continue;
            }

            if ($segment->shipped_at === null) {
                $segment->forceFill([
                    'size_bytes' => (int) $item['size_bytes'],
                    'sha256' => strtolower((string) $item['sha256']),
                    'plaintext_bytes' => (int) $item['plaintext_bytes'],
                    'end_time' => CarbonImmutable::parse((string) $item['end_time'])->utc(),
                    'shipped_at' => now(),
                ])->save();
            }

            $acknowledged[] = $segment->id;
        }

        if ($acknowledged !== []) {
            $instance->forceFill(['pitr_last_shipped_at' => now()])->save();
        }

        return ['acknowledged' => $acknowledged];
    }
}
