<?php

namespace Falak\Security;

use Falak\Alerting\Contracts\AlertTypes;
use Falak\Alerting\Contracts\Severity;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Identity\Contracts\PermissionRegistry;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Events\OrganizationDeleted;
use Falak\Kernel\Support\ModuleServiceProvider;
use Falak\Security\Application\Jobs\RunDueAudits;
use Falak\Security\Application\Listeners\HandleCommandOutcome;
use Falak\Security\Application\Listeners\LifecycleListener;
use Falak\Security\Events\CriticalFindingDetected;
use Falak\Security\Events\SecurityScoreDropped;
use Falak\Security\Events\UnexpectedPortDetected;
use Falak\Servers\Events\ServerDeleted;
use Falak\Servers\Events\ServerProvisioned;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

/**
 * The server security baseline (v0.10.0 §9): daily audits (security.audit), a score with its history and the
 * "Production ready" badge, and one-click fixes from the agent's allowlist (security.fix / security.undo).
 */
class SecurityServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/security.php', 'security');
    }

    protected function bootModule(): void
    {
        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register('security.view', [Role::Admin, Role::Developer, Role::Viewer], 'View servers\' security baseline reports', 'security');
        $registry->register('security.fix', [Role::Admin], 'Run security audits and apply or undo their fixes on servers', 'security');

        $types = $this->app->make(AlertTypes::class);
        $types->register(SecurityScoreDropped::ALERT_TYPE, 'Security score dropped by 10 points or more', 'Security', Severity::Warning, 'Fix in baseline');
        $types->register(CriticalFindingDetected::ALERT_TYPE, 'New high or critical security finding', 'Security', Severity::Critical, 'Fix in baseline');
        $types->register(UnexpectedPortDetected::ALERT_TYPE, 'Unexpected public port', 'Security', Severity::Warning, 'Review open ports');

        Event::listen(CommandFinished::class, [HandleCommandOutcome::class, 'handleFinished']);
        Event::listen(CommandFailed::class, [HandleCommandOutcome::class, 'handleFailed']);
        Event::listen(ServerProvisioned::class, [LifecycleListener::class, 'provisioned']);
        Event::listen(ServerDeleted::class, [LifecycleListener::class, 'serverDeleted']);
        Event::listen(OrganizationDeleted::class, [LifecycleListener::class, 'organizationDeleted']);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->job(new RunDueAudits)->hourly()->name('security:audits')->withoutOverlapping();
        });
    }
}
