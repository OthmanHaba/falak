<?php

namespace Falak\Secrets;

use Falak\Identity\Contracts\PermissionRegistry;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Events\OrganizationDeleted;
use Falak\Kernel\Support\ModuleServiceProvider;
use Falak\Secrets\Application\Jobs\PruneAccessLog;
use Falak\Secrets\Application\Listeners\DeleteOrganizationSecrets;
use Falak\Secrets\Contracts\SecretProviders;
use Falak\Secrets\Contracts\Secrets;
use Falak\Secrets\Domain\Models\Secret;
use Falak\Secrets\Domain\Policies\SecretPolicy;
use Falak\Secrets\Infrastructure\NullSecretProviders;
use Falak\Secrets\Infrastructure\SecretStore;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

class SecretsServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        // External providers fill this in (linked secrets); until then a linked secret fails to resolve.
        SecretProviders::class => NullSecretProviders::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/secrets.php', 'secrets');

        // Request-scoped: the accessedAs() stack never outlives a request or job (Octane).
        $this->app->scoped(Secrets::class, SecretStore::class);
    }

    protected function bootModule(): void
    {
        Gate::policy(Secret::class, SecretPolicy::class);

        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register(SecretPolicy::VIEW, [Role::Admin, Role::Developer, Role::Viewer], 'View secret names, versions, access log and usage (never values)', 'secrets');
        $registry->register(SecretPolicy::REVEAL, [Role::Admin, Role::Developer], 'Reveal values of non-sensitive secrets (API tokens need this ability explicitly)', 'secrets');
        $registry->register(SecretPolicy::MANAGE, [Role::Admin, Role::Developer], 'Create secrets, set values, roll back, disable versions and delete secrets', 'secrets');

        Event::listen(OrganizationDeleted::class, DeleteOrganizationSecrets::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->job(new PruneAccessLog)->dailyAt('03:50')->name('secrets:prune-access-log')->withoutOverlapping();
        });
    }
}
