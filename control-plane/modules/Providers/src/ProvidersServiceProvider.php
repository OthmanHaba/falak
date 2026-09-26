<?php

namespace Kiln\Providers;

use Illuminate\Support\Facades\Gate;
use Kiln\Identity\Contracts\PermissionRegistry;
use Kiln\Identity\Contracts\Role;
use Kiln\Kernel\Support\ModuleServiceProvider;
use Kiln\Providers\Contracts\ProviderGateway;
use Kiln\Providers\Domain\Models\ProviderCredential;
use Kiln\Providers\Domain\Policies\ProviderCredentialPolicy;
use Kiln\Providers\Infrastructure\AdapterFactory;
use Kiln\Providers\Infrastructure\EloquentProviderGateway;

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
