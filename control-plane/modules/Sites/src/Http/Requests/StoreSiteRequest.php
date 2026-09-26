<?php

namespace Kiln\Sites\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Sites\Application\Actions\CreateSite;
use Kiln\Sites\Contracts\BuildMode;
use Kiln\Sites\Contracts\Framework;
use Kiln\Sites\Contracts\SiteRuntime;

final class StoreSiteRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = app(CurrentOrganization::class)->requireId();

        return [
            'name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9 ._-]*$/', Rule::unique('sites_sites')->where('organization_id', $organizationId)],
            'slug' => ['nullable', 'string', 'max:50', 'regex:'.CreateSite::SLUG_PATTERN, Rule::unique('sites_sites', 'slug')],
            'framework' => ['required', Rule::enum(Framework::class)],
            'runtime' => ['nullable', Rule::enum(SiteRuntime::class)],
            'build_mode' => ['nullable', Rule::enum(BuildMode::class)],
            'server_ids' => ['required', 'array', 'min:1', 'max:50'],
            'server_ids.*' => ['string', 'size:26'],
            'leader_server_id' => ['nullable', 'string', 'size:26'],
            ...self::siteRules(),
            'isolated' => ['boolean'],
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
            'docker_image' => ['nullable', 'string', 'max:255', 'regex:#^[a-z0-9][a-z0-9._\-/:@]*$#'],
            'dockerfile' => ['nullable', 'string', 'max:255', 'regex:#^[A-Za-z0-9_.\-/]+$#', 'not_regex:#(^|/)\.\.(/|$)#'],
            'compose_file' => ['nullable', 'string', 'max:255', 'regex:#^[A-Za-z0-9_.\-/]+$#', 'not_regex:#(^|/)\.\.(/|$)#'],
            'health_check_path' => ['nullable', 'string', 'max:255', 'regex:#^/[^\s]*$#'],
            'test_domain_enabled' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'Use letters, numbers, spaces, dots, dashes and underscores.',
            'slug.regex' => 'Use lowercase letters, numbers and dashes.',
            'branch.regex' => 'Not a valid branch name.',
            'branch.not_regex' => 'Not a valid branch name.',
        ];
    }
}
