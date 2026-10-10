<?php

namespace Falak\Kernel\Security\Console;

use Falak\Kernel\Security\Casts\Sealed;
use Falak\Kernel\Security\DataKey;
use Falak\Kernel\Security\KeyRing;
use Falak\Kernel\Security\SealedColumns;
use Falak\Kernel\Security\Sealer;
use Illuminate\Console\Command;

/**
 * Start a new platform data key and re-seal every model-cast column under it, in batches. Interrupted?
 * Run it again with --resume: values already under the new key are skipped. Retired keys are kept, so
 * older database backups stay readable.
 *
 * Running processes keep sealing under the key they looked up as active for up to kernel.keys.active_ttl
 * seconds, so after a pass that changed anything it waits that long and passes again, until a pass finds
 * nothing left under an older key.
 */
final class RotateDataKeyCommand extends Command
{
    protected $signature = 'falak:keys:rotate-data
        {--resume : finish an interrupted rotation (re-seal under the active key without starting a new one)}';

    protected $description = 'Rotate the platform data key and re-encrypt every sealed column under the new key';

    public function handle(KeyRing $keys, Sealer $sealer, SealedColumns $columns): int
    {
        $key = $this->option('resume') ? $keys->platform() : $keys->rotate(DataKey::PLATFORM);
        $this->components->info(($this->option('resume') ? 'Resuming with' : 'New').' platform data key '.$key->id);

        $wait = KeyRing::activeTtl();
        $total = 0;

        for ($pass = 1; ; $pass++) {
            if ($pass > 1 && $wait > 0) {
                $this->components->info("Waiting {$wait}s for running processes to switch to the new key, then checking again");
                sleep($wait + 1);
            }

            $changed = $this->pass($key, $sealer, $columns);
            $total += $changed;

            // The first pass always gets a second one (unless nothing can lag): a process may still seal under the old key.
            if ($changed === 0 && ($pass > 1 || $wait === 0)) {
                break;
            }
        }

        $this->components->info("Re-encrypted {$total} value(s); nothing is left under an older platform key.");

        return self::SUCCESS;
    }

    private function pass(DataKey $key, Sealer $sealer, SealedColumns $columns): int
    {
        $batch = max(1, (int) config('kernel.keys.rotate_batch', 200));
        $total = 0;

        foreach ($columns->all() as $c) {
            $changed = SealedColumns::rewrite($c['table'], $c['primary_key'], $c['column'], function (string $value, string $id) use ($sealer, $key, $c) {
                if (! Sealer::isSealed($value) || Sealer::keyId($value) === $key->id) {
                    return null;
                }

                $aad = Sealed::aadFor($c['table'], $c['column'], $id);

                return $sealer->sealWith($key, $sealer->open($value, $aad), $aad);
            }, $batch);

            $total += $changed;

            if ($changed > 0) {
                $this->components->twoColumnDetail("{$c['table']}.{$c['column']}", (string) $changed);
            }
        }

        return $total;
    }
}
