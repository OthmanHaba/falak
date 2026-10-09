<?php

namespace Falak\Databases\Infrastructure\AgentRequests;

use Falak\Databases\Domain\Models\PitrSegment;
use Falak\Databases\Infrastructure\ObjectStorage\ObjectStores;
use Falak\Fleet\Contracts\AgentRequestHandler;
use Falak\Fleet\Contracts\Data\AgentCaller;
use Falak\Fleet\Contracts\Exceptions\AgentRequestRefused;
use Falak\Kernel\Security\BackupKeys;
use Illuminate\Support\Str;

/**
 * pitr.upload_urls (contracts/agent-protocol/requests): a presigned PUT URL and the encryption of each spool file of a
 * batch. Every segment gets a row and a data key of its own: cp generates a fresh key, kept sealed on the row (it
 * travels to the agent in this reply only); customer names the instance's age recipient (the agent generates the key).
 * A file the control plane already has (same name and content: its acknowledgment was lost) is answered `shipped`,
 * and the agent only deletes it.
 */
final class PitrUploadUrls implements AgentRequestHandler
{
    use ResolvesPitrInstance;

    public function __construct(
        private readonly ObjectStores $stores,
        private readonly BackupKeys $keys,
    ) {}

    public function handle(AgentCaller $caller, array $body): array
    {
        $instance = $this->instance($caller, $body);
        $provider = $instance->pitrStorageProvider;
        $customer = $instance->pitr_encryption_mode === BackupKeys::CUSTOMER;

        if ($provider === null) {
            throw new AgentRequestRefused('pitr_disabled', 'Point-in-time recovery has no storage provider.');
        }

        // Never Falak-held keys for a customer-held instance: without a valid recipient nothing ships.
        if ($customer && ! BackupKeys::validRecipient($instance->pitr_age_recipient)) {
            throw new AgentRequestRefused('bad_recipient', 'The keys are customer-held but the age public key is missing or invalid.');
        }

        $store = $this->stores->for($provider);
        $ttl = (int) config('databases.pitr.upload_url_ttl', 3600);
        $kind = (string) $body['kind'];
        $slots = [];

        foreach ((array) $body['segments'] as $ask) {
            $name = (string) $ask['name'];
            $sha = strtolower((string) $ask['sha256']);
            $segment = PitrSegment::query()->where('database_instance_id', $instance->id)->where('kind', $kind)
                ->where('name', $name)->where('plaintext_sha256', $sha)->first();

            if ($segment?->shipped_at !== null) {
                $slots[] = ['name' => $name, 'id' => $segment->id, 'shipped' => true];

                continue;
            }

            if ($segment === null) {
                $segment = new PitrSegment;
                $segment->id = strtolower((string) Str::ulid());
                $segment->fill([
                    'organization_id' => $instance->organization_id,
                    'database_instance_id' => $instance->id,
                    'server_id' => $instance->server_id,
                    'kind' => $kind,
                    'name' => $name,
                    'storage_provider_id' => $provider->id,
                    'object_key' => $store->key(Str::slug($instance->name).'-'.substr($instance->id, -6), 'pitr', $kind, "{$name}-{$segment->id}.zst.fkb"),
                    'plaintext_sha256' => $sha,
                    'plaintext_bytes' => (int) $ask['bytes'],
                ]);
            }

            // A fresh key for every upload (an earlier, unacknowledged one is overwritten with it).
            if ($customer) {
                $encryption = BackupKeys::sealing($segment->id, (string) $instance->pitr_age_recipient);
                $segment->fill(['encryption_mode' => BackupKeys::CUSTOMER, 'wrapped_key' => null, 'age_recipient' => $instance->pitr_age_recipient]);
            } else {
                [$encryption, $wrapped] = $this->keys->generate($instance->organization_id, $segment->id);
                $segment->fill(['encryption_mode' => BackupKeys::CP, 'wrapped_key' => $wrapped, 'age_recipient' => null]);
            }

            $segment->save();
            $slots[] = ['name' => $name, 'id' => $segment->id, 'url' => $store->presignPut($segment->object_key, $ttl), 'encryption' => $encryption];
        }

        return ['segments' => $slots];
    }
}
