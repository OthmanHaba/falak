<?php

namespace Kiln\Functions;

use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Kiln\Deployments\Contracts\FunctionSources;
use Kiln\Functions\Application\Listeners\ForgetDeletedFunction;
use Kiln\Functions\Infrastructure\FunctionScheduleSources;
use Kiln\Functions\Infrastructure\StoredFunctionSources;
use Kiln\Identity\Contracts\PermissionRegistry;
use Kiln\Identity\Contracts\Role;
use Kiln\Kernel\Support\ModuleServiceProvider;
use Kiln\Processes\Contracts\ScheduleSources;
use Kiln\Sites\Events\SiteDeleted;

/**
 * Cloud Functions (docs/plans/FUNCTIONS.md): code written in Kiln, versioned, deployed by Deployments to the servers'
 * function gateway, which starts instances on demand and scales them to zero.
 */
class FunctionsServiceProvider extends ModuleServiceProvider
{
    public const VIEW = 'functions.view';

    public const CREATE = 'functions.create';

    public const EDIT = 'functions.edit';

    public const DEPLOY = 'functions.deploy';

    /**
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        FunctionSources::class => StoredFunctionSources::class,
        ScheduleSources::class => FunctionScheduleSources::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/functions.php', 'functions');
    }

    protected function bootModule(): void
    {
        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register(self::VIEW, [Role::Admin, Role::Developer, Role::Viewer], 'View functions, their code and versions', 'functions');
        $registry->register(self::CREATE, [Role::Admin, Role::Developer], 'Create functions', 'functions');
        $registry->register(self::EDIT, [Role::Admin, Role::Developer], 'Edit function code (drafts)', 'functions');
        $registry->register(self::DEPLOY, [Role::Admin, Role::Developer], 'Deploy function versions, roll back and change scaling', 'functions');

        Event::listen(SiteDeleted::class, ForgetDeletedFunction::class);

        // Code is stored exactly as written: no trimmed lines or emptied files.
        $code = fn (Request $request) => $request->is('sites/*/function/draft', 'sites/*/function/deploy');
        TrimStrings::skipWhen($code);
        ConvertEmptyStringsToNull::skipWhen($code);
    }
}
