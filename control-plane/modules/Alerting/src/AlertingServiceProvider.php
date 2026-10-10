<?php

namespace Falak\Alerting;

use Falak\Alerting\Application\Console\DefaultRulesCommand;
use Falak\Alerting\Application\Jobs\PruneAlerting;
use Falak\Alerting\Application\Listeners\ApplyDefaultRulePack;
use Falak\Alerting\Application\Listeners\DeleteOrganizationAlerting;
use Falak\Alerting\Application\Listeners\MapModuleEvents;
use Falak\Alerting\Application\Listeners\RouteAlertableEvents;
use Falak\Alerting\Contracts\Alertable;
use Falak\Alerting\Contracts\AlertConditions;
use Falak\Alerting\Contracts\Alerts;
use Falak\Alerting\Contracts\AlertTypes;
use Falak\Alerting\Domain\Models\Channel;
use Falak\Alerting\Domain\Models\Rule;
use Falak\Alerting\Domain\Policies\ChannelPolicy;
use Falak\Alerting\Domain\Policies\RulePolicy;
use Falak\Alerting\Http\Channels\UserNotificationsChannel;
use Falak\Alerting\Infrastructure\DatabaseAlertConditions;
use Falak\Alerting\Infrastructure\InMemoryAlertTypes;
use Falak\Alerting\Infrastructure\QueuedAlerts;
use Falak\Alerting\Infrastructure\Senders\DiscordSender;
use Falak\Alerting\Infrastructure\Senders\EmailSender;
use Falak\Alerting\Infrastructure\Senders\SenderRegistry;
use Falak\Alerting\Infrastructure\Senders\SlackSender;
use Falak\Alerting\Infrastructure\Senders\TelegramSender;
use Falak\Alerting\Infrastructure\Senders\WebhookSender;
use Falak\Fleet\Events\AgentCameOnline;
use Falak\Fleet\Events\AgentWentOffline;
use Falak\Identity\Contracts\PermissionRegistry;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Events\OrganizationCreated;
use Falak\Identity\Events\OrganizationDeleted;
use Falak\Insights\Events\HeartbeatMissed;
use Falak\Insights\Events\IssueOpened;
use Falak\Insights\Events\IssueRegressed;
use Falak\Insights\Events\IssueResolved;
use Falak\Insights\Events\ThresholdBreached;
use Falak\Kernel\Support\ModuleServiceProvider;
use Falak\Servers\Events\ServerAttentionCleared;
use Falak\Servers\Events\ServerNeedsAttention;
use Falak\Servers\Events\ServerProvisioned;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

class AlertingServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        AlertTypes::class => InMemoryAlertTypes::class,
        Alerts::class => QueuedAlerts::class,
        AlertConditions::class => DatabaseAlertConditions::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/alerting.php', 'alerting');

        $this->app->singleton(SenderRegistry::class, fn ($app) => new SenderRegistry([
            $app->make(EmailSender::class),
            $app->make(SlackSender::class),
            $app->make(DiscordSender::class),
            $app->make(TelegramSender::class),
            $app->make(WebhookSender::class),
        ]));
    }

    protected function bootModule(): void
    {
        $this->loadViewsFrom($this->modulePath().'/resources/views', 'alerting');

        Gate::policy(Channel::class, ChannelPolicy::class);
        Gate::policy(Rule::class, RulePolicy::class);

        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register('alerting.view', [Role::Admin, Role::Developer, Role::Viewer], 'View alert rules, channels, history and receive in-app notifications', 'alerting');
        $registry->register('alerting.manage', [Role::Admin], 'Manage alert channels and rules', 'alerting');

        MapModuleEvents::registerTypes($this->app->make(AlertTypes::class));

        Event::listen(IssueOpened::class, [MapModuleEvents::class, 'issueOpened']);
        Event::listen(IssueRegressed::class, [MapModuleEvents::class, 'issueRegressed']);
        Event::listen(IssueResolved::class, [MapModuleEvents::class, 'issueResolved']);
        Event::listen(ThresholdBreached::class, [MapModuleEvents::class, 'thresholdBreached']);
        Event::listen(HeartbeatMissed::class, [MapModuleEvents::class, 'heartbeatMissed']);
        Event::listen(AgentWentOffline::class, [MapModuleEvents::class, 'agentWentOffline']);
        Event::listen(AgentCameOnline::class, [MapModuleEvents::class, 'agentCameOnline']);
        Event::listen(ServerProvisioned::class, [MapModuleEvents::class, 'serverProvisioned']);
        Event::listen(ServerNeedsAttention::class, [MapModuleEvents::class, 'serverNeedsAttention']);
        Event::listen(ServerAttentionCleared::class, [MapModuleEvents::class, 'serverAttentionCleared']);
        Event::listen(OrganizationCreated::class, ApplyDefaultRulePack::class);
        Event::listen(OrganizationDeleted::class, DeleteOrganizationAlerting::class);

        // Any module event implementing Alerting\Contracts\Alertable (instanceof check before resolving anything).
        Event::listen('*', function (string $eventName, array $payload) {
            if (($payload[0] ?? null) instanceof Alertable) {
                // app(), not $this->app: under the FrankenPHP worker the latter is the base app, not the request sandbox.
                app(RouteAlertableEvents::class)->handle($eventName, $payload);
            }
        });

        Broadcast::channel(UserNotificationsChannel::NAME, UserNotificationsChannel::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->job(new PruneAlerting)->dailyAt('03:40')->name('alerting:prune')->withoutOverlapping();
            // Alert groups registered since (a module's new area) join every organization's default rule pack.
            $schedule->command(DefaultRulesCommand::class)->dailyAt('03:45')->name('alerting:default-rules')->withoutOverlapping();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([DefaultRulesCommand::class]);
        }
    }
}
