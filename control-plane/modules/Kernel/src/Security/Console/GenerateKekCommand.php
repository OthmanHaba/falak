<?php

namespace Falak\Kernel\Security\Console;

use Falak\Kernel\Security\Aead;
use Falak\Kernel\Security\Kek\LocalKek;
use Illuminate\Console\Command;

/**
 * Development and custom installs: production installs get the KEK from install.sh.
 */
final class GenerateKekCommand extends Command
{
    protected $signature = 'falak:keys:generate-kek {path? : where to write it (default FALAK_KEK_PATH)}';

    protected $description = 'Write a new local key-encryption key (32 random bytes, mode 0400); never overwrites one';

    public function handle(): int
    {
        $path = (string) ($this->argument('path') ?: config('kernel.keys.local.path'));
        $path = str_starts_with($path, '/') ? $path : base_path($path);

        if (file_exists($path)) {
            $this->components->error("{$path} already exists. Replacing a KEK makes every secret it protects unreadable; see falak:keys:rotate-kek.");

            return self::FAILURE;
        }

        if (! is_dir(dirname($path)) && ! @mkdir(dirname($path), 0700, true)) {
            $this->components->error('Could not create '.dirname($path).'.');

            return self::FAILURE;
        }

        $key = random_bytes(Aead::KEY_BYTES);
        $tmp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        $old = umask(0377);

        try {
            $written = file_put_contents($tmp, $key) === Aead::KEY_BYTES && @link($tmp, $path);
        } finally {
            @unlink($tmp);
            umask($old);
        }

        if (! $written) {
            $this->components->error("Could not write {$path}.");

            return self::FAILURE;
        }

        chmod($path, 0400);
        $this->components->info("Wrote {$path} (KEK ".LocalKek::fingerprint($key).'). Keep a copy apart from database backups.');

        return self::SUCCESS;
    }
}
