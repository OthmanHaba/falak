<?php

namespace Falak\Kernel\Security\Console;

use Falak\Kernel\Security\DataKey;
use Falak\Kernel\Security\KeyRing;
use Falak\Kernel\Security\SealedColumns;
use Falak\Kernel\Security\Sealer;
use Illuminate\Console\Command;

/**
 * Start a new platform data key and re-seal every model-cast column under it, in batches. Interrupted?
 * Run it again with --resume: values already under the new key are skipped. Retired keys are kept, so
 * older database backups stay readable.
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

        $batch = max(1, (int) config('kernel.keys.rotate_batch', 200));
        $total = 0;

        foreach ($columns->all() as $c) {
            $aad = $c['aad'];

            $changed = SealedColumns::rewrite($c['table'], $c['primary_key'], $c['column'], function (string $value) use ($sealer, $key, $aad) {
                if (! Sealer::isSealed($value) || Sealer::keyId($value) === $key->id) {
                    return null;
                }

                return $sealer->sealWith($key, $sealer->open($value, $aad), $aad);
            }, $batch);

            $total += $changed;
            $this->components->twoColumnDetail("{$c['table']}.{$c['column']}", (string) $changed);
        }

        $this->components->info("Re-encrypted {$total} value(s).");

        return self::SUCCESS;
    }
}
