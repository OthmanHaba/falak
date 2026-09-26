<?php

namespace Kiln\Network;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Kiln\Alerting\Contracts\AlertTypes;
use Kiln\Alerting\Contracts\Severity;
use Kiln\Fleet\Events\CommandFailed;
use Kiln\Fleet\Events\CommandFinished;
use Kiln\Identity\Contracts\PermissionRegistry;
use Kiln\Identity\Contracts\Role;
use Kiln\Kernel\Support\ModuleServiceProvider;
use Kiln\Network\Application\Listeners\ForgetDeletedServer;
use Kiln\Network\Application\Listeners\HandleCommandOutcome;
use Kiln\Network\Application\Listeners\SeedFirewallOnProvisioning;
use Kiln\Network\Contracts\PrivateNetwork as PrivateNetworkContract;
use Kiln\Network\Domain\Models\FirewallRule;
use Kiln\Network\Domain\Models\PrivateNetwork;
use Kiln\Network\Domain\Policies\FirewallRulePolicy;
use Kiln\Network\Domain\Policies\PrivateNetworkPolicy;
use Kiln\Network\Events\FirewallApplied;
use Kiln\Network\Events\FirewallApplyFailed;
use Kiln\Network\Infrastructure\EloquentPrivateNetwork;
use Kiln\Servers\Events\ServerDeleted;
use Kiln\Servers\Events\ServerProvisioned;

class NetworkServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        PrivateNetworkContract::class => EloquentPrivateNetwork::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/network.php', 'network');
    }

    protected function bootModule(): void
    {
        Gate::policy(PrivateNetwork::class, PrivateNetworkPolicy::class);
        Gate::policy(FirewallRule::class, FirewallRulePolicy::class);

        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register('network.view', [Role::Admin, Role::Developer, Role::Viewer], 'View firewalls, private networks and load balancers', 'network');
        $registry->register('network.manage', [Role::Admin, Role::Developer], 'Manage firewall rules and private networks', 'network');

        $types = $this->app->make(AlertTypes::class);
        $types->register(FirewallApplyFailed::ALERT_TYPE, 'Firewall apply failed', 'Network', Severity::Critical);
        $types->register(FirewallApplied::ALERT_TYPE, 'Firewall applied again', 'Network', Severity::Info);

        Event::listen(CommandFinished::class, [HandleCommandOutcome::class, 'handleFinished']);
        Event::listen(CommandFailed::class, [HandleCommandOutcome::class, 'handleFailed']);
        Event::listen(ServerProvisioned::class, SeedFirewallOnProvisioning::class);
        Event::listen(ServerDeleted::class, ForgetDeletedServer::class);
    }
}
