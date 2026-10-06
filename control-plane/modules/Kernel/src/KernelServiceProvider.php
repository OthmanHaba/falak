<?php

namespace Falak\Kernel;

use Falak\Kernel\Network\EndpointGuard;
use Falak\Kernel\Security\Casts\SealedGuard;
use Falak\Kernel\Security\Console\CheckKeysCommand;
use Falak\Kernel\Security\Console\GenerateKekCommand;
use Falak\Kernel\Security\Console\RotateDataKeyCommand;
use Falak\Kernel\Security\Console\RotateKekCommand;
use Falak\Kernel\Security\KeyEncryptionKeys;
use Falak\Kernel\Security\KeyRing;
use Falak\Kernel\Security\SealedColumns;
use Falak\Kernel\Security\Sealer;
use Falak\Kernel\Support\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;

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
        EndpointGuard::class => EndpointGuard::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/kernel.php', 'kernel');
    }

    protected function bootModule(): void
    {
        // A sealed value bound to another row (replicate(), a changed key) is refused before it is written.
        Event::listen('eloquent.saving: *', fn (string $event, array $payload) => SealedGuard::check($payload[0]));

        if ($this->app->runningInConsole()) {
            $this->commands([CheckKeysCommand::class, GenerateKekCommand::class, RotateKekCommand::class, RotateDataKeyCommand::class]);
        }
    }
}
