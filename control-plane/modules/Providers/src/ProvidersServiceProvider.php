<?php

namespace Falak\Providers;

use Falak\Identity\Contracts\PermissionRegistry;
use Falak\Identity\Contracts\Role;
use Falak\Kernel\Support\ModuleServiceProvider;
use Falak\Providers\Contracts\ProviderGateway;
use Falak\Providers\Domain\Models\ProviderCredential;
use Falak\Providers\Domain\Policies\ProviderCredentialPolicy;
use Falak\Providers\Infrastructure\AdapterFactory;
use Falak\Providers\Infrastructure\EloquentProviderGateway;
use Illuminate\Support\Facades\Gate;

class ProvidersServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        ProviderGateway::class => EloquentProviderGateway::class,
        AdapterFactory::class => AdapterFactory::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/providers.php', 'providers');
    }

    protected function bootModule(): void
    {
        Gate::policy(ProviderCredential::class, ProviderCredentialPolicy::class);

        $permissions = $this->app->make(PermissionRegistry::class);
        $permissions->register('providers.view', [Role::Admin, Role::Developer, Role::Viewer], 'View cloud provider credentials and catalogs', 'providers');
        $permissions->register('providers.manage', [Role::Admin], 'Add, rotate and remove cloud provider credentials', 'providers');
    }
}
