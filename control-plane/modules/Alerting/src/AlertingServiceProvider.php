<?php

namespace Kiln\Alerting;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Kiln\Alerting\Application\Jobs\PruneAlerting;
use Kiln\Alerting\Application\Listeners\DeleteOrganizationAlerting;
use Kiln\Alerting\Application\Listeners\MapModuleEvents;
use Kiln\Alerting\Application\Listeners\RouteAlertableEvents;
use Kiln\Alerting\Contracts\Alertable;
use Kiln\Alerting\Contracts\Alerts;
use Kiln\Alerting\Contracts\AlertTypes;
use Kiln\Alerting\Domain\Models\Channel;
use Kiln\Alerting\Domain\Models\Rule;
use Kiln\Alerting\Domain\Policies\ChannelPolicy;
use Kiln\Alerting\Domain\Policies\RulePolicy;
use Kiln\Alerting\Http\Channels\UserNotificationsChannel;
use Kiln\Alerting\Infrastructure\InMemoryAlertTypes;
use Kiln\Alerting\Infrastructure\QueuedAlerts;
use Kiln\Alerting\Infrastructure\Senders\DiscordSender;
use Kiln\Alerting\Infrastructure\Senders\EmailSender;
use Kiln\Alerting\Infrastructure\Senders\SenderRegistry;
use Kiln\Alerting\Infrastructure\Senders\SlackSender;
use Kiln\Alerting\Infrastructure\Senders\TelegramSender;
use Kiln\Alerting\Infrastructure\Senders\WebhookSender;
use Kiln\Fleet\Events\AgentCameOnline;
use Kiln\Fleet\Events\AgentWentOffline;
use Kiln\Identity\Contracts\PermissionRegistry;
use Kiln\Identity\Contracts\Role;
use Kiln\Identity\Events\OrganizationDeleted;
use Kiln\Insights\Events\HeartbeatMissed;
use Kiln\Insights\Events\IssueOpened;
use Kiln\Insights\Events\IssueRegressed;
use Kiln\Insights\Events\IssueResolved;
use Kiln\Insights\Events\ThresholdBreached;
use Kiln\Kernel\Support\ModuleServiceProvider;
use Kiln\Servers\Events\ServerAttentionCleared;
use Kiln\Servers\Events\ServerNeedsAttention;
use Kiln\Servers\Events\ServerProvisioned;

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
        });
    }
}
