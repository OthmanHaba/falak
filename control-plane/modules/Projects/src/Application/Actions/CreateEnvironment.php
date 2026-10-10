<?php

namespace Falak\Projects\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Projects\Domain\Models\Environment;
use Falak\Projects\Domain\Models\Project;
use Falak\Projects\Domain\Models\Service;
use Falak\Projects\Events\EnvironmentCreated;
use Falak\Sites\Contracts\Data\SitePlacement;
use Falak\Sites\Contracts\SiteFactory;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Create an environment, empty or duplicated from another environment of the project. Duplicating
 * copies every site's configuration and variables (via Sites' SiteFactory) at the same canvas
 * position and service name — without servers, which the user picks per service. Databases hold
 * data and are not duplicated.
 */
final class CreateEnvironment
{
    /** @var list<string> problems while duplicating (a site that could not be copied, skipped databases) */
    public array $warnings = [];

    public function __construct(
        private readonly SiteFactory $sites,
        private readonly LinkService $link,
        private readonly AuditLog $audit,
    ) {}

    /** @var array<string, string> service name => id of its copy (sites only) */
    public array $copies = [];

    /**
     * $preview makes a pull request's preview of $from (Previews): `fork` (untrusted, no secrets), only the services
     * named in `include` copied, with `sites` overrides per service name ({@see SiteFactory::duplicate()}), and the
     * `shared` names resolving in $from.
     *
     * @param  ?array{fork: bool, include: list<string>, shared: list<string>, sites: array<string, array<string, mixed>>}  $preview
     *
     * @throws ValidationException
     */
    public function __invoke(Project $project, string $name, ?string $userId = null, ?Environment $from = null, ?array $preview = null): Environment
    {
        $this->copies = [];
        $this->warnings = [];
        $name = trim($name);

        if ($from !== null && $from->project_id !== $project->id) {
            throw ValidationException::withMessages(['from_environment_id' => 'The environment belongs to another project.']);
        }

        if ($project->environments()->whereRaw('lower(name) = ?', [Str::lower($name)])->exists()) {
            throw ValidationException::withMessages(['name' => "An environment named \"{$name}\" already exists."]);
        }

        $environment = Environment::query()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'name' => $name,
            'slug' => self::uniqueSlug($project, $name),
            'is_production' => false,
            'forked_from_id' => $from?->id,
            'created_by' => $userId,
            'is_preview' => $preview !== null,
            'is_fork_preview' => (bool) ($preview['fork'] ?? false),
            'shared_services' => $preview !== null ? array_values($preview['shared']) : null,
        ]);

        $this->audit->record('project.environment_created', 'project', $project->id, [
            'environment' => $environment->slug,
            'from' => $from?->slug,
        ], $project->organization_id);

        EnvironmentCreated::dispatch($environment->id, $project->id, $project->organization_id, $environment->slug, false, $from?->id);

        if ($from !== null) {
            $this->duplicate($from, $environment, $userId, $preview);
        }

        return $environment;
    }

    /**
     * @param  ?array{fork: bool, include: list<string>, shared: list<string>, sites: array<string, array<string, mixed>>}  $preview
     */
    private function duplicate(Environment $from, Environment $to, ?string $userId, ?array $preview): void
    {
        $skippedDatabases = [];
        $include = $preview !== null ? array_map(fn ($name) => Service::handle((string) $name), $preview['include']) : null;

        foreach ($from->services()->get() as $service) {
            /** @var Service $service */
            if ($include !== null && ! in_array(Service::handle($service->name), $include, true)) {
                continue;
            }

            if ($service->kind === ServiceKind::Database) {
                $skippedDatabases[] = $service->name;

                continue;
            }

            // Groups are layout of the source environment: copies land at the same place on screen, ungrouped.
            ['x' => $x, 'y' => $y] = MoveService::absolute($service);

            try {
                $overrides = $preview !== null ? [
                    ...(array) ($preview['sites'][$service->name] ?? []),
                    'strip_secrets' => (bool) $preview['fork'],
                    'push_to_deploy' => false,
                ] : [];
                $copy = $this->sites->duplicate(
                    $service->ref_id,
                    ['name_suffix' => $to->slug, ...$overrides],
                    new SitePlacement($to->project_id, $to->id, $x, $y, $service->name),
                    $userId,
                );

                // Normally done by the SiteCreated listener already; linking is idempotent.
                ($this->link)($to, ServiceKind::Site, $copy->site->id, $service->name, $x, $y);
                $this->copies[$service->name] = $copy->site->id;
                array_push($this->warnings, ...$copy->warnings);
            } catch (ValidationException $e) {
                $this->warnings[] = "{$service->name} was not copied: ".implode(' ', array_merge(...array_values($e->errors())));
            }
        }

        if ($skippedDatabases !== [] && $preview === null) {
            $this->warnings[] = 'Databases are not duplicated ('.implode(', ', $skippedDatabases).'); create them in this environment so references resolve.';
        }
    }

    public static function uniqueSlug(Project $project, string $name, ?string $exceptId = null): string
    {
        $base = Str::limit(Str::slug($name), 56, '') ?: 'env';

        if (in_array($base, Environment::RESERVED_SLUGS, true)) {
            $base .= '-env';
        }

        $slug = $base;

        for ($i = 2; Environment::query()->where('project_id', $project->id)->where('slug', $slug)->when($exceptId, fn ($q, $id) => $q->whereKeyNot($id))->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
