<?php

namespace Falak\Kernel\Security\Console;

use Falak\Kernel\Security\Aead;
use Falak\Kernel\Security\DataKey;
use Falak\Kernel\Security\KeyEncryptionKeys;
use Falak\Kernel\Security\KeyRing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * --json prints one line for falak-ctl (kek rotate, backup, restore):
 *   {"ok":true,"provider":"local","kek_id":"…","data_keys":3,"stale":0,"failed":0,"kek_ids":["…"]}
 * stale: data keys not wrapped by the current KEK; kek_ids: every KEK id the data keys are wrapped by.
 */
final class CheckKeysCommand extends Command
{
    protected $signature = 'falak:keys:check
        {--kek-only : check the KEK alone, not every data key (start of the container roles that do not migrate)}
        {--json : one line of JSON, no other output}';

    protected $description = 'Check the key-encryption key and that every data key unwraps with it (prints no secrets)';

    public function handle(KeyEncryptionKeys $keks, KeyRing $keys): int
    {
        $report = ['ok' => false, 'provider' => null, 'kek_id' => null, 'data_keys' => 0, 'stale' => 0, 'failed' => 0, 'kek_ids' => []];

        try {
            $kek = $keks->current();
            $probe = random_bytes(Aead::KEY_BYTES);
            $context = ['falak:purpose' => 'check'];

            if (! hash_equals($probe, $kek->unwrap($kek->wrap($probe, $context), $context))) {
                return $this->finish($report, 'The KEK did not unwrap what it wrapped.');
            }

            $report['provider'] = $kek->provider();
            $report['kek_id'] = $kek->id();
        } catch (Throwable $e) {
            return $this->finish($report, $e->getMessage());
        }

        $this->detail('KEK', "{$kek->provider()} {$kek->id()}");

        // Before the first migration there are no data keys to check.
        if ($this->option('kek-only') || ! Schema::hasTable('kernel_data_keys')) {
            return $this->finish(['ok' => true] + $report);
        }

        foreach (DataKey::query()->orderBy('created_at')->cursor() as $key) {
            $report['data_keys']++;
            $report['kek_ids'][$key->kek_id] = true;

            if ($key->kek_provider !== $kek->provider() || $key->kek_id !== $kek->id()) {
                $report['stale']++;
            }

            try {
                $keys->material($key);
            } catch (Throwable $e) {
                $report['failed']++;
                $this->error("data key {$key->id} ({$key->purpose}): {$e->getMessage()}");
            }
        }

        $report['kek_ids'] = array_keys($report['kek_ids']);
        $report['ok'] = $report['failed'] === 0;
        $this->detail('Data keys', "{$report['data_keys']} ({$report['stale']} wrapped by an earlier KEK)");

        if ($report['stale'] > 0 && $report['ok'] && ! $this->option('json')) {
            $this->components->warn('Some data keys are wrapped by an earlier KEK: run falak:keys:rotate-kek.');
        }

        return $this->finish($report);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function finish(array $report, ?string $error = null): int
    {
        if ($error !== null) {
            $report['error'] = $error;
            $this->error($error);
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_SLASHES));
        }

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function detail(string $label, string $value): void
    {
        if (! $this->option('json')) {
            $this->components->twoColumnDetail($label, $value);
        }
    }

    public function error($string, $verbosity = null): void
    {
        if (! $this->option('json')) {
            $this->components->error((string) $string);
        }
    }
}
