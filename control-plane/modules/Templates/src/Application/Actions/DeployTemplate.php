<?php

namespace Kiln\Templates\Application\Actions;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Kiln\Deployments\Contracts\DeploymentTrigger;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Projects\Contracts\Data\EnvironmentData;
use Kiln\Sites\Contracts\Data\CreatedSite;
use Kiln\Sites\Contracts\Data\DomainChoice;
use Kiln\Sites\Contracts\Data\SiteData;
use Kiln\Sites\Contracts\Data\SitePlacement;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Sites\Contracts\SiteDomains;
use Kiln\Sites\Contracts\SiteFactory;
use Kiln\Templates\Application\Compose\KilnPlaceholders;
use Kiln\Templates\Application\Inputs\InputResolver;
use Kiln\Templates\Domain\Template;

/**
 * Create a compose site from a template and start its first deployment (docs/COMPOSE_TEMPLATES.md §3, §5):
 * resolve inputs (generated once), pick domains (generated, test or the user's), render the Kiln placeholders,
 * then SiteFactory::create with the §5 compose fields, placed in the environment at the given position.
 */
final class DeployTemplate
{
    private const SLUG_ATTEMPTS = 8;

    public function __construct(
        private readonly SiteFactory $sites,
        private readonly SiteDirectory $directory,
        private readonly DeploymentTrigger $deployments,
        private readonly InputResolver $inputs,
        private readonly AuditLog $audit,
        private readonly SiteDomains $domains,
    ) {}

    /**
     * @param  array<string, mixed>  $inputs  KEY => value entered in the configure form (generated ones may be sent back)
     * @param  array<string, mixed>  $domains  public service => domain name | {type: generated|test|custom, name?} (missing: the organization's default)
     * @param  list<string>  $serverIds  leader first
     *
     * @throws ValidationException
     */
    public function __invoke(
        EnvironmentData $environment,
        ?string $userId,
        Template $template,
        array $inputs,
        array $domains,
        array $serverIds,
        ?string $name = null,
        ?int $x = null,
        ?int $y = null,
        bool $deploy = true,
    ): DeployedTemplate {
        $name = $this->name($environment->organizationId, $name ?: $template->name);
        $choices = $this->choices($template, $domains);
        $values = $this->inputs->resolve($template, $inputs);
        $base = Str::limit(trim(Str::slug($name), '-'), 44, '') ?: $template->slug;

        for ($attempt = 1; ; $attempt++) {
            $slug = $attempt === 1 ? $base : ($attempt < self::SLUG_ATTEMPTS ? "{$base}-{$attempt}" : $base.'-'.strtolower(Str::random(5)));
            // Generated names embed the slug: resolve per attempt.
            $customDomains = $this->resolveDomains($environment->organizationId, $template, $choices, $slug, $serverIds);
            $effective = $this->effectiveDomains($template, $customDomains, $slug);

            try {
                $created = $this->create($environment, $userId, $template, $name, $slug, $values, $customDomains, $effective, $serverIds, $x, $y);

                break;
            } catch (ValidationException $e) {
                // Slugs are unique across organizations: retry with a suffix (the test domain follows the slug).
                if ($attempt < self::SLUG_ATTEMPTS + 1 && array_key_exists('slug', $e->errors()) && count($e->errors()) === 1) {
                    continue;
                }

                throw $e;
            }
        }

        $warnings = $created->warnings;
        $deploymentId = null;

        if ($deploy) {
            try {
                $deploymentId = $this->deployments->deploy($created->site->id, $userId);
            } catch (ValidationException $e) {
                $warnings[] = 'The first deploy did not start: '.collect($e->errors())->flatten()->first();
            }
        }

        $this->audit->record('templates.deployed', 'site', $created->site->id, [
            'template' => $template->slug,
            'version' => $template->version,
            'source' => $template->source->value,
            'environment_id' => $environment->id,
        ], $environment->organizationId, $userId);

        return new DeployedTemplate($created->site, $deploymentId, array_values($warnings), $effective);
    }

    /**
     * @param  array<string, string>  $values
     * @param  array<string, ?string>  $customDomains
     * @param  array<string, string>  $effective
     * @param  list<string>  $serverIds
     */
    private function create(EnvironmentData $environment, ?string $userId, Template $template, string $name, string $slug, array $values, array $customDomains, array $effective, array $serverIds, ?int $x, ?int $y): CreatedSite
    {
        $render = fn (string $text) => KilnPlaceholders::render($text, $effective, $slug);

        return $this->sites->create($environment->organizationId, $userId, [
            'name' => $name,
            'slug' => $slug,
            'framework' => 'docker',
            'runtime' => 'compose',
            'server_ids' => $serverIds,
            'leader_server_id' => $serverIds[0] ?? null,
            // docs/COMPOSE_TEMPLATES.md §5
            'compose_source' => 'inline',
            'compose_content' => $render($template->composeYaml),
            'public_services' => array_map(fn (array $public) => [
                'service' => $public['service'],
                'port' => $public['port'],
                'domain' => $customDomains[$public['service']] ?? null,
            ], $template->public),
            'variables' => array_map($render, $values),
            'template' => ['slug' => $template->slug, 'version' => $template->version, 'source' => $template->source->value],
        ], new SitePlacement($environment->projectId, $environment->id, $x, $y, $name));
    }

    /**
     * A site name that is free in the organization ("n8n", "n8n-2", …).
     */
    private function name(string $organizationId, string $wanted): string
    {
        $wanted = trim((string) preg_replace('/[^A-Za-z0-9 ._-]+/', '-', $wanted), ' .-_') ?: 'service';
        $wanted = mb_substr($wanted, 0, 56);
        $taken = array_map(fn (SiteData $site) => strtolower($site->name), $this->directory->forOrganization($organizationId));
        $name = $wanted;

        for ($i = 2; in_array(strtolower($name), $taken, true); $i++) {
            $name = "{$wanted}-{$i}";
        }

        return $name;
    }

    /**
     * @param  array<string, mixed>  $domains  service => domain name | {type, name?} | null
     * @return array<string, ?DomainChoice> null: the organization's default
     *
     * @throws ValidationException
     */
    private function choices(Template $template, array $domains): array
    {
        $choices = [];

        foreach ($template->publicServices() as $service) {
            $choices[$service] = DomainChoice::fromInput($domains[$service] ?? null, "domains.{$service}");
        }

        return $choices;
    }

    /**
     * Host names per public service: a generated `<service>-<slug>.<ip>.<suffix>`, the user's domain, or null for the
     * test domain (docs/COMPOSE_TEMPLATES.md §3).
     *
     * @param  array<string, ?DomainChoice>  $choices
     * @param  list<string>  $serverIds
     * @return array<string, ?string>
     *
     * @throws ValidationException
     */
    private function resolveDomains(string $organizationId, Template $template, array $choices, string $slug, array $serverIds): array
    {
        $errors = [];
        $result = [];
        $seen = [];

        foreach ($template->publicServices() as $service) {
            $field = "domains.{$service}";

            try {
                $domain = $this->domains->resolveChoice($organizationId, $choices[$service] ?? null, "{$service}-{$slug}", $serverIds, $field);
            } catch (ValidationException $e) {
                $errors += array_map(fn (array $messages) => $messages[0], $e->errors());

                continue;
            }

            if ($domain !== null && isset($seen[$domain])) {
                $errors[$field] = 'Each public service needs its own domain.';
            }

            if ($domain !== null) {
                $seen[$domain] = true;
            }

            $result[$service] = $domain;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $result;
    }

    /**
     * Domains Kiln placeholders render to: the custom domain, else the test domain (`<slug>.<base>` for the first
     * public service, `<service>-<slug>.<base>` for the others — docs/COMPOSE_TEMPLATES.md §1.3).
     *
     * @param  array<string, ?string>  $custom
     * @return array<string, string>
     */
    private function effectiveDomains(Template $template, array $custom, string $slug): array
    {
        $base = self::testDomainBase();
        $domains = [];

        foreach ($template->publicServices() as $index => $service) {
            $domains[$service] = $custom[$service] ?? ($index === 0 ? "{$slug}.{$base}" : "{$service}-{$slug}.{$base}");
        }

        return $domains;
    }

    public static function testDomainBase(): ?string
    {
        $base = config('sites.test_domain');

        return is_string($base) && trim($base, '. ') !== '' ? strtolower(trim($base, '. ')) : null;
    }
}
