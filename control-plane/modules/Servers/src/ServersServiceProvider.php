<?php

namespace Kiln\Servers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Kiln\Fleet\Events\AgentCameOnline;
use Kiln\Fleet\Events\AgentEnrolled;
use Kiln\Fleet\Events\AgentFactsReported;
use Kiln\Fleet\Events\AgentRevoked;
use Kiln\Fleet\Events\AgentWentOffline;
use Kiln\Fleet\Events\CommandFailed;
use Kiln\Fleet\Events\CommandFinished;
use Kiln\Identity\Contracts\PermissionRegistry;
use Kiln\Identity\Contracts\Role;
use Kiln\Identity\Events\OrganizationDeleted;
use Kiln\Kernel\Support\ModuleServiceProvider;
use Kiln\Servers\Application\Listeners\BroadcastConnectivity;
use Kiln\Servers\Application\Listeners\DeleteOrganizationServers;
use Kiln\Servers\Application\Listeners\HandleCommandOutcome;
use Kiln\Servers\Application\Listeners\RecordReportedFacts;
use Kiln\Servers\Application\Listeners\StartProvisioningOnEnrollment;
use Kiln\Servers\Contracts\ServerDirectory;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Servers\Domain\Models\SshKey;
use Kiln\Servers\Domain\Policies\ServerPolicy;
use Kiln\Servers\Domain\Policies\SshKeyPolicy;
use Kiln\Servers\Http\Channels\ServerChannel;
use Kiln\Servers\Infrastructure\EloquentServerDirectory;
use Kiln\Servers\Infrastructure\ProvisioningPlanBuilder;

class ServersServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        ServerDirectory::class => EloquentServerDirectory::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/servers.php', 'servers');

        $this->app->bind(ProvisioningPlanBuilder::class, fn () => new ProvisioningPlanBuilder((array) config('servers')));
    }

    protected function bootModule(): void
    {
        Gate::policy(Server::class, ServerPolicy::class);
        Gate::policy(SshKey::class, SshKeyPolicy::class);

        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register('servers.view', [Role::Admin, Role::Developer, Role::Viewer], 'View servers, metrics and provisioning logs', 'servers');
        $registry->register('servers.create', [Role::Admin, Role::Developer], 'Create servers', 'servers');
        $registry->register('servers.manage', [Role::Admin, Role::Developer], 'Manage PHP, settings, SSH keys and re-provision servers', 'servers');
        $registry->register('servers.delete', [Role::Admin], 'Delete servers', 'servers');
        $registry->register('ssh_keys.manage', [Role::Admin, Role::Developer], 'Add and remove organization SSH keys', 'servers');

        Event::listen(AgentEnrolled::class, StartProvisioningOnEnrollment::class);
        Event::listen(AgentFactsReported::class, RecordReportedFacts::class);
        Event::listen(CommandFinished::class, [HandleCommandOutcome::class, 'handleFinished']);
        Event::listen(CommandFailed::class, [HandleCommandOutcome::class, 'handleFailed']);
        Event::listen([AgentWentOffline::class, AgentCameOnline::class, AgentRevoked::class], BroadcastConnectivity::class);
        Event::listen(OrganizationDeleted::class, DeleteOrganizationServers::class);

        Broadcast::channel(ServerChannel::NAME, ServerChannel::class);
    }
}
