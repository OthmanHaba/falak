<?php

namespace Kiln\Sites\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Projects\Contracts\ProjectDirectory;
use Kiln\Sites\Application\Actions\CreateSite;
use Kiln\Sites\Contracts\BuildMode;
use Kiln\Sites\Contracts\ComposeSource;
use Kiln\Sites\Contracts\Data\DomainChoice;
use Kiln\Sites\Contracts\Data\SitePlacement;
use Kiln\Sites\Contracts\Framework;
use Kiln\Sites\Contracts\SiteFactory;
use Kiln\Sites\Contracts\SiteRuntime;

final class StoreSiteRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...self::rulesFor(app(CurrentOrganization::class)->requireId()),
            // Optional Projects placement (defaults to the organization's default project / production).
            'project_id' => ['nullable', 'string', 'size:26'],
            'environment_id' => ['nullable', 'string', 'size:26'],
        ];
    }

    /**
     * Site creation rules (also applied by {@see SiteFactory}).
     *
     * @return array<string, mixed>
     */
    public static function rulesFor(string $organizationId): array
    {
        return [
            'name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9 ._-]*$/', Rule::unique('sites_sites')->where('organization_id', $organizationId)],
            'slug' => ['nullable', 'string', 'max:50', 'regex:'.CreateSite::SLUG_PATTERN, Rule::unique('sites_sites', 'slug')],
            'framework' => ['required_unless:runtime,compose', 'nullable', Rule::enum(Framework::class)],
            'runtime' => ['nullable', Rule::enum(SiteRuntime::class)],
            'build_mode' => ['nullable', Rule::enum(BuildMode::class)],
            'server_ids' => ['required', 'array', 'min:1', 'max:50'],
            'server_ids.*' => ['string', 'size:26'],
            'leader_server_id' => ['nullable', 'string', 'size:26'],
            ...self::siteRules(),
            'isolated' => ['boolean'],
            ...self::composeRules(),
            // Initial environment (encrypted); values may contain ${{ service.KEY }} references.
            'variables' => ['nullable', 'array', 'max:500'],
            'variables.*' => ['nullable', 'string', 'max:65535'],
            // Non-compose sites: {type: generated|test|custom, name?} or a custom domain name (routed with automatic TLS).
            'domain' => ['nullable', DomainChoice::rule()],
            'template' => ['nullable', 'array:slug,version,source'],
            'template.slug' => ['required_with:template', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'template.version' => ['required_with:template', 'string', 'max:32'],
            'template.source' => ['required_with:template', Rule::in(['catalog', 'custom'])],
        ];
    }

    /**
     * Compose site fields (docs/COMPOSE_TEMPLATES.md §5), shared with Settings → Compose.
     *
     * @return array<string, mixed>
     */
    public static function composeRules(): array
    {
        return [
            'compose_source' => ['nullable', Rule::enum(ComposeSource::class)],
            'compose_content' => ['nullable', 'string', 'max:'.(int) config('sites.compose.max_bytes', 262144)],
            'public_services' => ['nullable', 'array', 'max:20'],
            'public_services.*' => ['array:service,port,domain,health_check_path'],
            'public_services.*.service' => ['required', 'string', 'max:63'],
            'public_services.*.port' => ['required', 'integer', 'between:1,65535'],
            'public_services.*.domain' => ['nullable', DomainChoice::rule()],
            'public_services.*.health_check_path' => ['nullable', 'string', 'max:255', 'regex:#^/\S*$#'],
        ];
    }

    /**
     * Rules shared with the settings form.
     *
     * @return array<string, mixed>
     */
    public static function siteRules(): array
    {
        return [
            'php_version' => ['nullable', Rule::in((array) config('sites.php_versions'))],
            'node_version' => ['nullable', Rule::in((array) config('sites.node_versions'))],
            'source_connection_id' => ['nullable', 'string', 'size:26'],
            'repository' => ['nullable', 'required_with:source_connection_id', 'string', 'max:255', 'regex:#^[A-Za-z0-9_.\-/:@~]+$#'],
            'branch' => ['nullable', 'required_with:repository', 'string', 'max:255', 'regex:#^[A-Za-z0-9_.\-/]+$#', 'not_regex:#(^[/.-]|\.\.|//|/$|\.lock$)#'],
            'push_to_deploy' => ['boolean'],
            'web_directory' => ['nullable', 'string', 'max:255', 'regex:#^[A-Za-z0-9_.\-/]*$#', 'not_regex:#(^|/)\.\.?(/|$)#'],
            'app_port' => ['nullable', 'integer', 'between:1024,65535'],
            'container_port' => ['nullable', 'integer', 'between:1,65535'],
            'docker_image' => ['nullable', 'string', 'max:255', 'regex:#^[a-z0-9][a-z0-9._\-/:@]*$#'],
            'dockerfile' => ['nullable', 'string', 'max:255', 'regex:#^[A-Za-z0-9_.\-/]+$#', 'not_regex:#(^|/)\.\.(/|$)#'],
            'compose_file' => ['nullable', 'string', 'max:255', 'regex:#^[A-Za-z0-9_.\-/]+$#', 'not_regex:#(^|/)\.\.(/|$)#'],
            'health_check_path' => ['nullable', 'string', 'max:255', 'regex:#^/[^\s]*$#'],
            'test_domain_enabled' => ['boolean'],
        ];
    }

    /**
     * The placement must belong to the organization (and the environment to the project, when both are given).
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->hasAny(['project_id', 'environment_id'])) {
                return;
            }

            $organizationId = app(CurrentOrganization::class)->requireId();
            $projects = app(ProjectDirectory::class);
            $projectId = $this->input('project_id');
            $environmentId = $this->input('environment_id');

            if (is_string($environmentId) && $environmentId !== '') {
                $environment = $projects->environment(strtolower($environmentId));

                if ($environment === null || $environment->organizationId !== $organizationId) {
                    $validator->errors()->add('environment_id', 'Unknown environment.');
                } elseif (is_string($projectId) && $projectId !== '' && $environment->projectId !== strtolower($projectId)) {
                    $validator->errors()->add('environment_id', 'The environment does not belong to the project.');
                }
            } elseif (is_string($projectId) && $projectId !== '') {
                $project = $projects->find(strtolower($projectId));

                if ($project === null || $project->organizationId !== $organizationId) {
                    $validator->errors()->add('project_id', 'Unknown project.');
                }
            }
        }];
    }

    public function placement(): ?SitePlacement
    {
        $projectId = $this->validated('project_id');
        $environmentId = $this->validated('environment_id');

        return $projectId || $environmentId
            ? new SitePlacement($projectId ? strtolower((string) $projectId) : null, $environmentId ? strtolower((string) $environmentId) : null)
            : null;
    }

    /**
     * Site fields only (without the Projects placement).
     *
     * @return array<string, mixed>
     */
    public function siteData(): array
    {
        return array_diff_key($this->validated(), array_flip(['project_id', 'environment_id']));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::errorMessages();
    }

    /**
     * @return array<string, string>
     */
    public static function errorMessages(): array
    {
        return [
            'name.regex' => 'Use letters, numbers, spaces, dots, dashes and underscores.',
            'slug.regex' => 'Use lowercase letters, numbers and dashes.',
            'branch.regex' => 'Not a valid branch name.',
            'branch.not_regex' => 'Not a valid branch name.',
        ];
    }
}
