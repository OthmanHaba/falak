<?php

namespace Kiln\Builds\Application\Console;

use Illuminate\Console\Command;
use Kiln\Builds\Contracts\BuildStatus;
use Kiln\Builds\Domain\Models\Build;

/**
 * Whether the built-in registry may be stopped for garbage collection (`kiln-ctl registry gc` asks first): exit 0 when
 * no image build is queued or running, 1 when one is (a push during garbage collection could lose layers).
 */
final class RegistryIdleCommand extends Command
{
    protected $signature = 'kiln:registry-idle';

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
