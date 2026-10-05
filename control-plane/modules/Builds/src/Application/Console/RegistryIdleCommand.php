<?php

namespace Falak\Builds\Application\Console;

use Falak\Builds\Contracts\BuildStatus;
use Falak\Builds\Domain\Models\Build;
use Illuminate\Console\Command;

/**
 * Whether the built-in registry may be stopped for garbage collection (`falak-ctl registry gc` asks first): exit 0 when
 * no image build is queued or running, 1 when one is (a push during garbage collection could lose layers).
 */
final class RegistryIdleCommand extends Command
{
    protected $signature = 'falak:registry-idle';

    protected $description = 'Exit 0 when no image build (docker / compose) is queued or running, 1 otherwise';

    public function handle(): int
    {
        $active = Build::query()->whereIn('status', BuildStatus::active())->where('mode', '!=', 'native')->count();

        if ($active > 0) {
            $this->line("{$active} image build(s) queued or running.");

            return self::FAILURE;
        }

        $this->line('No image build is queued or running.');

        return self::SUCCESS;
    }
}
