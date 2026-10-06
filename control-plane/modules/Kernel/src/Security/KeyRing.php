<?php

namespace Falak\Kernel\Security;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Data keys: one active key per purpose (the platform, or one organization), created on first use and
 * wrapped by the KEK. Unwrapped keys are cached in this process's memory only, never in a shared cache.
 *
 * A key created inside the caller's database transaction is only "pending" until it is seen committed: if
 * that transaction rolls back, the key row is gone, and a cached copy would go on sealing values under a key
 * id that no longer exists. Pending keys are checked against the database on every use until then.
 */
class KeyRing
{
    use RedactsKeyMaterial;

    /** @var array<string, string> data key id => key bytes (committed keys) */
    private array $material = [];

    /** @var array<string, array{0: DataKey, 1: int}> purpose => [active data key, when it was looked up] */
    private array $active = [];

    /** @var array<string, string> data key id => key bytes, for keys created inside a transaction */
    private array $pending = [];

    public function __construct(private readonly KeyEncryptionKeys $keks) {}

    /** Long-lived workers look the active key up again after this many seconds (a rotation elsewhere). */
    public static function activeTtl(): int
    {
        return max(0, (int) config('kernel.keys.active_ttl', 60));
    }

    /** The active platform data key (model casts), created on first use. */
    public function platform(): DataKey
    {
        return $this->activeFor(DataKey::PLATFORM);
    }

    /** The active data key of an organization, created on first use. */
    public function organization(string $organizationId): DataKey
    {
        return $this->activeFor(DataKey::organization($organizationId));
    }

    /** The organization's active data key, or null when it has none yet. */
    public function findOrganization(string $organizationId): ?DataKey
    {
        return $this->current(DataKey::organization($organizationId));
    }

    public function activeFor(string $purpose): DataKey
    {
        if (isset($this->active[$purpose]) && time() - $this->active[$purpose][1] < self::activeTtl()) {
            $key = $this->active[$purpose][0];

            if (! isset($this->pending[$key->id]) || $this->settle($key->id)) {
                return $key;
            }
        }

        unset($this->active[$purpose]);

        $key = $this->current($purpose)
            // Serialize creation so two processes sealing at once don't each create a key (harmless, but noisy).
            ?? Cache::lock("kernel:data-key:{$purpose}", 10)->block(10, fn () => $this->current($purpose) ?? $this->create($purpose));

        $this->active[$purpose] = [$key, time()];

        return $key;
    }

    /**
     * Start a new data key for the purpose and retire the previous ones. They keep opening what they sealed;
     * falak:keys:rotate-data re-encrypts that under the new key.
     */
    public function rotate(string $purpose): DataKey
    {
        $key = DB::transaction(function () use ($purpose) {
            DataKey::query()->where('purpose', $purpose)->whereNull('retired_at')->update(['retired_at' => now()]);

            return $this->create($purpose);
        });

        $this->active[$purpose] = [$key, time()];

        return $key;
    }

    /**
     * The bytes of a data key, unwrapped with the KEK that wrapped it.
     *
     * @throws KeyUnavailable|DecryptionFailed
     */
    public function material(DataKey|string $key): string
    {
        $id = $key instanceof DataKey ? $key->id : $key;

        if (isset($this->material[$id])) {
            return $this->material[$id];
        }

        if (isset($this->pending[$id])) {
            $bytes = $this->pending[$id];

            if (! $this->settle($id)) {
                throw new DecryptionFailed("Data key {$id} does not exist (its transaction was rolled back).");
            }

            return $bytes;
        }

        $key = $key instanceof DataKey ? $key : DataKey::query()->find($id);

        if ($key === null) {
            throw new DecryptionFailed("Data key {$id} does not exist.");
        }

        $bytes = $this->keks->for($key->kek_provider, $key->kek_id)->unwrap($key->wrapped_key, $key->context());

        if (strlen($bytes) !== Aead::KEY_BYTES) {
            throw new DecryptionFailed("Data key {$id} did not unwrap to a 32-byte key.");
        }

        return $this->material[$id] = $bytes;
    }

    /**
     * Wrap the key under the current KEK (KEK rotation). Returns false when it already is.
     */
    public function rewrap(DataKey $key, bool $force = false): bool
    {
        $kek = $this->keks->current();

        if (! $force && $key->kek_provider === $kek->provider() && $key->kek_id === $kek->id()) {
            return false;
        }

        $bytes = $this->material($key);

        $key->forceFill([
            'wrapped_key' => $kek->wrap($bytes, $key->context()),
            'kek_provider' => $kek->provider(),
            'kek_id' => $kek->id(),
        ])->save();

        return true;
    }

    /** Forget unwrapped keys (tests, and after a KEK rotation). */
    public function flush(): void
    {
        $this->material = [];
        $this->active = [];
        $this->pending = [];
    }

    /**
     * Whether a pending key still exists. Seen outside any transaction, it is committed: cached like any
     * other key from then on.
     */
    private function settle(string $id): bool
    {
        if (! DataKey::query()->whereKey($id)->exists()) {
            unset($this->pending[$id]);

            return false;
        }

        if (DB::transactionLevel() === 0) {
            $this->material[$id] = $this->pending[$id];
            unset($this->pending[$id]);
        }

        return true;
    }

    private function current(string $purpose): ?DataKey
    {
        return DataKey::query()->where('purpose', $purpose)->whereNull('retired_at')
            ->orderByDesc('created_at')->orderByDesc('id')->first();
    }

    private function create(string $purpose): DataKey
    {
        $kek = $this->keks->current();
        $bytes = random_bytes(Aead::KEY_BYTES);

        $key = new DataKey(['purpose' => $purpose]);
        $key->id = $key->newUniqueId();
        $key->forceFill([
            'wrapped_key' => $kek->wrap($bytes, $key->context()),
            'kek_provider' => $kek->provider(),
            'kek_id' => $kek->id(),
        ])->save();

        if (DB::transactionLevel() === 0) {
            $this->material[$key->id] = $bytes;
        } else {
            $this->pending[$key->id] = $bytes;
        }

        return $key;
    }
}
