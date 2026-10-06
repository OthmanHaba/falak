<?php

namespace Falak\Kernel\Security\Console;

use Falak\Kernel\Security\DataKey;
use Falak\Kernel\Security\KeyEncryptionKeys;
use Falak\Kernel\Security\KeyRing;
use Illuminate\Console\Command;
use Throwable;

/**
 * Re-wrap data keys under the current KEK. Values are not touched (they're sealed by the data keys).
 * Local KEK: put the new file at FALAK_KEK_PATH and the old one at FALAK_KEK_PREVIOUS_PATH first
 * (`falak-ctl kek rotate` does it). Resumable: keys already under the current KEK are skipped.
 */
final class RotateKekCommand extends Command
{
    protected $signature = 'falak:keys:rotate-kek
        {--all : also re-wrap keys already under the current KEK id (after rotating the key inside KMS or Vault)}';

    protected $description = 'Re-wrap every data key under the current key-encryption key';

    public function handle(KeyEncryptionKeys $keks, KeyRing $keys): int
    {
        $kek = $keks->current();
        $this->components->info("Re-wrapping data keys under {$kek->provider()} KEK {$kek->id()}");

        $rewrapped = 0;
        $failed = 0;

        foreach (DataKey::query()->orderBy('created_at')->orderBy('id')->cursor() as $key) {
            try {
                if ($keys->rewrap($key, (bool) $this->option('all'))) {
                    $rewrapped++;
                }
            } catch (Throwable $e) {
                $failed++;
                $this->components->error("data key {$key->id} ({$key->purpose}): {$e->getMessage()}");
            }
        }

        $keys->flush();
        $this->components->twoColumnDetail('Re-wrapped', (string) $rewrapped);

        if ($failed > 0) {
            $this->components->error("{$failed} data key(s) could not be re-wrapped: keep the previous KEK and run this again.");

            return self::FAILURE;
        }

        $this->components->info('Done. The previous KEK is no longer needed by the database; export a new emergency kit (falak-ctl kek export).');

        return self::SUCCESS;
    }
}
