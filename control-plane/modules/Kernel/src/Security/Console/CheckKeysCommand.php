<?php

namespace Falak\Kernel\Security\Console;

use Falak\Kernel\Security\Aead;
use Falak\Kernel\Security\DataKey;
use Falak\Kernel\Security\KeyEncryptionKeys;
use Falak\Kernel\Security\KeyRing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class CheckKeysCommand extends Command
{
    protected $signature = 'falak:keys:check';

    protected $description = 'Check the key-encryption key and that every data key unwraps with it (prints no secrets)';

    public function handle(KeyEncryptionKeys $keks, KeyRing $keys): int
    {
        try {
            $kek = $keks->current();
            $probe = random_bytes(Aead::KEY_BYTES);
            $context = ['falak:purpose' => 'check'];

            if (! hash_equals($probe, $kek->unwrap($kek->wrap($probe, $context), $context))) {
                $this->components->error('The KEK did not unwrap what it wrapped.');

                return self::FAILURE;
            }
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('KEK', "{$kek->provider()} {$kek->id()}");

        // Before the first migration there are no data keys to check.
        if (! Schema::hasTable('kernel_data_keys')) {
            return self::SUCCESS;
        }

        $failed = 0;
        $total = 0;
        $stale = 0;

        foreach (DataKey::query()->orderBy('created_at')->cursor() as $key) {
            $total++;

            if ($key->kek_provider !== $kek->provider() || $key->kek_id !== $kek->id()) {
                $stale++;
            }

            try {
                $keys->material($key);
            } catch (Throwable $e) {
                $failed++;
                $this->components->error("data key {$key->id} ({$key->purpose}): {$e->getMessage()}");
            }
        }

        $this->components->twoColumnDetail('Data keys', "{$total} ({$stale} wrapped by an earlier KEK)");

        if ($stale > 0 && $failed === 0) {
            $this->components->warn('Some data keys are wrapped by an earlier KEK: run falak:keys:rotate-kek.');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
