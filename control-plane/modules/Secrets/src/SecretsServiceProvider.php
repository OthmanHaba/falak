<?php

namespace Falak\Secrets;

use Falak\Alerting\Contracts\AlertTypes;
use Falak\Alerting\Contracts\Severity;
use Falak\Identity\Contracts\PermissionRegistry;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Events\OrganizationDeleted;
use Falak\Kernel\Support\ModuleServiceProvider;
use Falak\Secrets\Application\Jobs\PollLinkedSecrets;
use Falak\Secrets\Application\Jobs\PruneAccessLog;
use Falak\Secrets\Application\Listeners\DeleteOrganizationSecrets;
use Falak\Secrets\Contracts\SecretProviders;
use Falak\Secrets\Contracts\Secrets;
use Falak\Secrets\Domain\Models\Secret;
use Falak\Secrets\Domain\Models\SecretProvider;
use Falak\Secrets\Domain\Policies\SecretPolicy;
use Falak\Secrets\Domain\Policies\SecretProviderPolicy;
use Falak\Secrets\Events\LinkedSecretChanged;
use Falak\Secrets\Events\ProviderRecovered;
use Falak\Secrets\Events\ProviderUnreachable;
use Falak\Secrets\Infrastructure\ExternalSecretProviders;
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
    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/secrets.php', 'secrets');

        // Request-scoped: the accessedAs() stack never outlives a request or job (Octane).
        $this->app->scoped(Secrets::class, SecretStore::class);

        // Request-scoped too: a provider that failed during one deployment is not retried for each of its secrets.
        $this->app->scoped(ExternalSecretProviders::class);
        $this->app->scoped(SecretProviders::class, fn ($app) => $app->make(ExternalSecretProviders::class));
    }

    protected function bootModule(): void
    {
        Gate::policy(Secret::class, SecretPolicy::class);
        Gate::policy(SecretProvider::class, SecretProviderPolicy::class);

        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register(SecretPolicy::VIEW, [Role::Admin, Role::Developer, Role::Viewer], 'View secret names, versions, access log and usage (never values)', 'secrets');
        $registry->register(SecretPolicy::REVEAL, [Role::Admin, Role::Developer], 'Reveal values of non-sensitive secrets (API tokens need this ability explicitly)', 'secrets');
        $registry->register(SecretPolicy::MANAGE, [Role::Admin, Role::Developer], 'Create secrets, set values, roll back, disable versions and delete secrets', 'secrets');
        $registry->register(SecretPolicy::PROVIDERS_MANAGE, [Role::Admin], 'Add, edit, test and delete external secret providers (Vault, AWS, 1Password, …)', 'secrets');

        $types = $this->app->make(AlertTypes::class);
        $types->register(ProviderUnreachable::ALERT_TYPE, 'Secret provider unreachable', 'Secrets', Severity::Warning);
        $types->register(ProviderRecovered::ALERT_TYPE, 'Secret provider reachable again', 'Secrets', Severity::Info);
        $types->register(LinkedSecretChanged::ALERT_TYPE, 'Linked secret changed upstream', 'Secrets', Severity::Info);

        Event::listen(OrganizationDeleted::class, DeleteOrganizationSecrets::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->job(new PruneAccessLog)->dailyAt('03:50')->name('secrets:prune-access-log')->withoutOverlapping();
            $schedule->job(new PollLinkedSecrets)->everyMinute()->name('secrets:poll-linked')->withoutOverlapping(10);
        });
    }
}
