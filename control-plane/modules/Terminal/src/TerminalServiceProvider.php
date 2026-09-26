<?php

namespace Kiln\Terminal;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Kiln\Fleet\Events\CommandFailed;
use Kiln\Fleet\Events\CommandFinished;
use Kiln\Fleet\Events\CommandOutputReceived;
use Kiln\Identity\Contracts\PermissionRegistry;
use Kiln\Identity\Contracts\Role;
use Kiln\Kernel\Support\ModuleServiceProvider;
use Kiln\Servers\Events\ServerDeleted;
use Kiln\Terminal\Application\Jobs\SweepTerminalSessions;
use Kiln\Terminal\Application\Listeners\CloseSessionsOfDeletedServer;
use Kiln\Terminal\Application\Listeners\HandleTerminalCommandOutcome;
use Kiln\Terminal\Application\Listeners\StreamTerminalOutput;
use Kiln\Terminal\Domain\Models\TerminalSession;
use Kiln\Terminal\Domain\Policies\TerminalSessionPolicy;
use Kiln\Terminal\Http\Channels\TerminalSessionChannel;

class TerminalServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/terminal.php', 'terminal');
    }

    protected function bootModule(): void
    {
        Gate::policy(TerminalSession::class, TerminalSessionPolicy::class);

        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register('terminal.open', [Role::Admin], 'Open web terminal sessions on servers', 'terminal');
        $registry->register('terminal.attach', [Role::Admin], "Watch other members' shared terminal sessions", 'terminal');
        $registry->register('terminal.control', [Role::Admin], 'Type into and close terminal sessions opened by others', 'terminal');
        $registry->register('terminal.recordings.view', [Role::Admin], "Replay other members' terminal recordings", 'terminal');

        RateLimiter::for('terminal-input', function (Request $request) {
            // Throttling runs before route-model binding, so the parameter is usually still the raw id.
            $session = $request->route('session');
            $sessionKey = $session instanceof TerminalSession ? $session->id : (is_string($session) ? $session : '');

            return Limit::perMinute(1200)->by(($request->user()?->getAuthIdentifier() ?? $request->ip()).'|'.$sessionKey);
        });

        // Synchronous on purpose (keystroke echo latency) — see StreamTerminalOutput.
        Event::listen(CommandOutputReceived::class, StreamTerminalOutput::class);
        Event::listen(CommandFinished::class, [HandleTerminalCommandOutcome::class, 'handleFinished']);
        Event::listen(CommandFailed::class, [HandleTerminalCommandOutcome::class, 'handleFailed']);
        Event::listen(ServerDeleted::class, CloseSessionsOfDeletedServer::class);

        Broadcast::channel(TerminalSessionChannel::NAME, TerminalSessionChannel::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->job(new SweepTerminalSessions)->everyMinute()->name('terminal:sweep')->withoutOverlapping();
        });
    }
}
