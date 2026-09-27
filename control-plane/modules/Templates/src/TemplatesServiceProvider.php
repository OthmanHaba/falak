<?php

namespace Kiln\Templates;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as Http;
use Kiln\Identity\Contracts\PermissionRegistry;
use Kiln\Identity\Contracts\Role;
use Kiln\Kernel\Support\ModuleServiceProvider;
use Kiln\Sites\Contracts\ComposeInspector;
use Kiln\Templates\Application\Catalog\Catalog;
use Kiln\Templates\Application\Catalog\TemplateParser;
use Kiln\Templates\Application\Compose\ComposeAnalyzer;
use Kiln\Templates\Application\Compose\SiteCompose;
use Kiln\Templates\Application\Console\RenderTemplateCommand;
use Kiln\Templates\Application\Import\HostResolver;
use Kiln\Templates\Application\Import\RemoteFetcher;
use Kiln\Templates\Infrastructure\DnsHostResolver;
use Kiln\Templates\Infrastructure\FallbackComposeAnalyzer;
use Kiln\Templates\Infrastructure\FilesystemCatalog;
use Kiln\Templates\Infrastructure\GuardedHttpFetcher;
use Kiln\Templates\Infrastructure\InspectorComposeAnalyzer;
use Kiln\Templates\Infrastructure\SiteDataCompose;

/**
 * One-click templates backed by Docker Compose (docs/COMPOSE_TEMPLATES.md §2–§4): the curated catalog, custom
 * organization templates, the input engine and "deploy from template".
 */
class TemplatesServiceProvider extends ModuleServiceProvider
{
    public const VIEW = 'templates.view';

    public const MANAGE = 'templates.manage';

    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        HostResolver::class => DnsHostResolver::class,
        SiteCompose::class => SiteDataCompose::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/templates.php', 'templates');

        $this->app->singleton(Catalog::class, fn (Application $app) => new FilesystemCatalog(
            $app->make(Cache::class),
            $app->make(TemplateParser::class),
            (string) config('templates.catalog_path'),
            (int) config('templates.cache_ttl', 3600),
        ));

        // The compose runtime's inspector once it is bound (lane A); until then a local stand-in with the same
        // default policy. After lane A merges: bind InspectorComposeAnalyzer unconditionally and delete
        // FallbackComposeAnalyzer.
        $this->app->bind(ComposeAnalyzer::class, fn (Application $app) => $app->bound(ComposeInspector::class)
            ? new InspectorComposeAnalyzer($app)
            : new FallbackComposeAnalyzer);

        $this->app->bind(RemoteFetcher::class, fn (Application $app) => new GuardedHttpFetcher(
            $app->make(Http::class),
            $app->make(HostResolver::class),
            (int) config('templates.max_bytes', 262144),
            (int) config('templates.fetch.timeout', 10),
            (int) config('templates.fetch.max_redirects', 3),
        ));
    }

    protected function bootModule(): void
    {
        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register(self::VIEW, [Role::Admin, Role::Developer, Role::Viewer], 'Browse the template catalog and the organization\'s templates', 'templates');
        $registry->register(self::MANAGE, [Role::Admin, Role::Developer], 'Import, edit and delete the organization\'s templates', 'templates');

        if ($this->app->runningInConsole()) {
            $this->commands([RenderTemplateCommand::class]);
        }
    }
}
