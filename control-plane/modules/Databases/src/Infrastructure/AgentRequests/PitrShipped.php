<?php

namespace Falak\Databases\Infrastructure\AgentRequests;

use Carbon\CarbonImmutable;
use Falak\Databases\Domain\Models\PitrSegment;
use Falak\Databases\Infrastructure\ObjectStorage\ObjectStores;
use Falak\Databases\Infrastructure\ObjectStorage\StorageRequestFailed;
use Falak\Fleet\Contracts\AgentRequestHandler;
use Falak\Fleet\Contracts\Data\AgentCaller;
use Illuminate\Support\Facades\Log;

/**
 * pitr.shipped: segments the agent uploaded. A segment is acknowledged (and only then deleted from the spool by the
 * agent) when it is one this instance asked a URL for, with the same name and content, **and** the object is in the
 * storage as reported: a HEAD finds it with the reported size, and (objects up to databases.pitr.verify_max_bytes) its
 * SHA-256 is the reported one. Anything else is not acknowledged: the agent keeps the file and uploads it again later.
 */
final class PitrShipped implements AgentRequestHandler
{
    use ResolvesPitrInstance;

    public function __construct(private readonly ObjectStores $stores) {}

    public function handle(AgentCaller $caller, array $body): array
    {
        $instance = $this->instance($caller, $body);
        $acknowledged = [];

        foreach ((array) $body['segments'] as $item) {
            $segment = PitrSegment::query()->with('storageProvider')->whereKey(strtolower((string) $item['id']))
                ->where('database_instance_id', $instance->id)->where('kind', (string) $body['kind'])
                ->where('name', (string) $item['name'])->where('plaintext_sha256', strtolower((string) $item['plaintext_sha256']))
                ->first();

            if ($segment === null) {
                continue;
            }

            if ($segment->shipped_at === null) {
                $size = (int) $item['size_bytes'];
                $sha = strtolower((string) $item['sha256']);

                if (! $this->stored($segment, $size, $sha)) {
                    continue;
                }

                $segment->forceFill([
                    'size_bytes' => $size,
                    'sha256' => $sha,
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

    /** Whether the object is in the storage as the agent reports it. */
    private function stored(PitrSegment $segment, int $size, string $sha): bool
    {
        if ($segment->storageProvider === null) {
            return false;
        }

        try {
            $store = $this->stores->for($segment->storageProvider);

            if ($store->size($segment->object_key) !== $size) {
                return false;
            }

            return $size > (int) config('databases.pitr.verify_max_bytes', 64 * 1024 ** 2) || hash_equals($store->sha256($segment->object_key), $sha);
        } catch (StorageRequestFailed $e) {
            Log::info('A shipped PITR segment could not be checked.', ['segment_id' => $segment->id, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
