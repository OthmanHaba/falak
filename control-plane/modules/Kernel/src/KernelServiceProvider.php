<?php

namespace Falak\Kernel;

use Falak\Kernel\Security\Console\CheckKeysCommand;
use Falak\Kernel\Security\Console\GenerateKekCommand;
use Falak\Kernel\Security\Console\RotateDataKeyCommand;
use Falak\Kernel\Security\Console\RotateKekCommand;
use Falak\Kernel\Security\KeyEncryptionKeys;
use Falak\Kernel\Security\KeyRing;
use Falak\Kernel\Security\SealedColumns;
use Falak\Kernel\Security\Sealer;
use Falak\Kernel\Support\ModuleServiceProvider;

/**
 * Shared infrastructure every module may use: the key hierarchy (KEK, data keys, Sealer and the
 * Sealed / SealedArray casts). Registered before the modules.
 */
class KernelServiceProvider extends ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $singletons = [
        KeyEncryptionKeys::class => KeyEncryptionKeys::class,
        KeyRing::class => KeyRing::class,
        Sealer::class => Sealer::class,
        SealedColumns::class => SealedColumns::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/kernel.php', 'kernel');
    }

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([CheckKeysCommand::class, GenerateKekCommand::class, RotateKekCommand::class, RotateDataKeyCommand::class]);
        }
    }
}
