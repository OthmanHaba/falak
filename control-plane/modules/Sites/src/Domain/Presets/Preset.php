<?php

namespace Kiln\Sites\Domain\Presets;

use Kiln\Sites\Contracts\BuildMode;
use Kiln\Sites\Contracts\Data\SharedPath;
use Kiln\Sites\Contracts\Framework;
use Kiln\Sites\Contracts\SiteRuntime;

/**
 * Framework defaults applied when a site is created (all editable afterwards).
 */
final readonly class Preset
{
    /**
     * @param  list<SiteRuntime>  $runtimes  allowed runtimes, first = default
     * @param  list<SharedPath>  $sharedPaths
     * @param  array<string, string>  $environment  initial variables (APP_KEY etc. filled in by the action)
     * @param  array<string, bool>  $laravel
     */
    public function __construct(
        public Framework $framework,
        public array $runtimes,
        public string $webDirectory,
        public array $sharedPaths,
        public string $deployScript,
        public array $environment = [],
        public array $laravel = [],
        public ?string $healthCheckPath = null,
    ) {}

    public function defaultRuntime(): SiteRuntime
    {
        return $this->runtimes[0];
    }

    public function defaultBuildMode(SiteRuntime $runtime): BuildMode
    {
        return $runtime->buildModes()[0];
    }

    public static function for(Framework $framework): self
    {
        $php = [SiteRuntime::FrankenPhp, SiteRuntime::PhpFpm];
        $js = [SiteRuntime::Node, SiteRuntime::Bun, SiteRuntime::Deno, SiteRuntime::Docker];

        return match ($framework) {
            Framework::Laravel => new self($framework, $php, 'public', self::paths(['storage', 'directory'], ['.env', 'file']), self::laravelScript(), [
                'APP_NAME' => '',
                'APP_ENV' => 'production',
                'APP_KEY' => '',
                'APP_DEBUG' => 'false',
                'APP_URL' => '',
                // Files, not stderr: under FrankenPHP / PHP-FPM the web requests' stderr is shared by every site on the
                // server; the agent tails storage/logs per site (see EloquentServerSites).
                'LOG_CHANNEL' => 'daily',
            ], ['scheduler' => true, 'horizon' => false, 'octane' => false], '/up'),
            Framework::Statamic => new self($framework, $php, 'public', self::paths(['storage', 'directory'], ['.env', 'file'], ['content', 'directory'], ['users', 'directory'], ['public/assets', 'directory']), self::laravelScript("\$KILN_PHP please stache:warm\n"), [
                'APP_NAME' => '',
                'APP_ENV' => 'production',
                'APP_KEY' => '',
                'APP_DEBUG' => 'false',
                'APP_URL' => '',
            ], ['scheduler' => true]),
            Framework::Symfony => new self($framework, $php, 'public', self::paths(['var/log', 'directory'], ['.env.local', 'file']), <<<'SH'
                $KILN_FETCH

                cd "$KILN_RELEASE_DIR"
                $KILN_PHP bin/console cache:clear --env=prod --no-debug
                if [ "$KILN_IS_LEADER" = "1" ]; then
                    $KILN_PHP bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
                fi

                $KILN_ACTIVATE
                $KILN_RESTART_PROCS
                SH, ['APP_ENV' => 'prod', 'APP_SECRET' => '']),
            Framework::WordPress => new self($framework, $php, '', self::paths(['wp-content/uploads', 'directory'], ['wp-config.php', 'file']), <<<'SH'
                $KILN_FETCH

                $KILN_ACTIVATE
                SH),
            Framework::Php => new self($framework, $php, 'public', [], <<<'SH'
                $KILN_FETCH

                $KILN_ACTIVATE
                $KILN_RESTART_PROCS
                SH),
            Framework::Next => new self($framework, $js, '', [], self::nodeScript(), ['NODE_ENV' => 'production', 'NEXT_TELEMETRY_DISABLED' => '1'], healthCheckPath: '/'),
            Framework::Nuxt => new self($framework, $js, '', [], self::nodeScript(), ['NODE_ENV' => 'production', 'NITRO_PRESET' => 'node-server'], healthCheckPath: '/'),
            Framework::Node => new self($framework, $js, '', [], self::nodeScript(), ['NODE_ENV' => 'production'], healthCheckPath: '/'),
            // Built sites: kiln-builder packages the detected output dir (dist/, build/, out/, public/) as the release root.
            Framework::Static => new self($framework, [SiteRuntime::Static], '', [], <<<'SH'
                $KILN_FETCH

                $KILN_ACTIVATE
                SH),
            Framework::Docker => new self($framework, [SiteRuntime::Docker, SiteRuntime::Compose], '', [], <<<'SH'
                $KILN_FETCH

                $KILN_ACTIVATE
                SH, healthCheckPath: '/'),
        };
    }

    /**
     * @param  array{0: string, 1: string}  ...$paths
     * @return list<SharedPath>
     */
    private static function paths(array ...$paths): array
    {
        return array_map(fn (array $path) => new SharedPath($path[0], $path[1]), array_values($paths));
    }

    private static function laravelScript(string $extra = ''): string
    {
        return <<<SH
            \$KILN_FETCH

            cd "\$KILN_RELEASE_DIR"
            if [ "\$KILN_IS_LEADER" = "1" ]; then
                \$KILN_PHP artisan migrate --force
            fi
            \$KILN_PHP artisan optimize
            \$KILN_PHP artisan storage:link --force
            {$extra}
            \$KILN_ACTIVATE
            \$KILN_RESTART_PROCS
            SH;
    }

    private static function nodeScript(): string
    {
        return <<<'SH'
            $KILN_FETCH

            $KILN_ACTIVATE
            $KILN_RESTART_PROCS
            SH;
    }
}
