<?php

namespace Kiln\Sites\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Sites\Application\ComposeSettings;
use Kiln\Sites\Contracts\ComposeSource;
use Kiln\Sites\Contracts\SiteRuntime;
use Kiln\Sites\Domain\Models\ComposeVersion;
use Kiln\Sites\Domain\Models\Site;
use Kiln\Sites\Events\SiteUpdated;
use Kiln\Sites\Infrastructure\Compose\YamlComposeInspector;

/**
 * Settings → Compose: source (repo path / inline content, versioned), public services. Changes apply on the
 * next deploy; routing (public services) is re-applied by Edge on SiteUpdated.
 */
final class UpdateComposeSettings
{
    public function __construct(
        private readonly ComposeSettings $settings,
        private readonly YamlComposeInspector $inspector,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array{compose_source?: ?string, compose_file?: ?string, compose_content?: ?string, public_services?: ?array<int, array<string, mixed>>}  $data
     * @return array{changed: list<string>, version: ?int}
     */
    public function __invoke(Site $site, array $data, ?string $userId): array
    {
        if ($site->runtime !== SiteRuntime::Compose) {
            throw ValidationException::withMessages(['compose_source' => 'Only Docker Compose sites have compose settings.']);
        }

        $site->loadMissing('targets');
        $content = array_key_exists('compose_content', $data) && is_string($data['compose_content']) ? $data['compose_content'] : null;
        $source = isset($data['compose_source']) ? ComposeSource::from((string) $data['compose_source']) : ($site->compose_source ?? ComposeSource::Repo);
        $summary = null;

        if ($source === ComposeSource::Inline) {
            $current = ComposeVersion::query()->where('site_id', $site->id)->orderByDesc('version')->first();
            $content ??= $current?->content;

            if ($content === null) {
                throw ValidationException::withMessages(['compose_content' => 'Paste a compose file.']);
            }

            $summary = $this->settings->validateInline($site->organization_id, $content);
        } elseif ($content !== null && trim($content) !== '') {
            throw ValidationException::withMessages(['compose_content' => 'Repository sources read the compose file from the repository.']);
        }

        $public = array_key_exists('public_services', $data)
            ? $this->settings->publicServices($this->settings->resolveDomainChoices($site->organization_id, array_values((array) $data['public_services']), $site->slug, $site->leaderFirstServerIds(), $site->id), $site->serverIds(), $summary, $site)
            : ($summary !== null ? $this->settings->publicServices(array_values(array_map(fn ($p) => (array) $p, (array) $site->public_services)), $site->serverIds(), $summary, $site) : (array) $site->public_services);

        $version = null;

        $changed = DB::transaction(function () use ($site, $source, $data, $content, $public, $userId, &$version) {
            $before = ComposeVersion::query()->where('site_id', $site->id)->max('version');

            if ($source === ComposeSource::Inline && $content !== null) {
                $version = $this->settings->saveVersion($site, $content, $userId);
            }

            $site->fill([
                'compose_source' => $source,
                'compose_file' => $source === ComposeSource::Repo ? ((isset($data['compose_file']) && $data['compose_file'] !== '') ? (string) $data['compose_file'] : (array_key_exists('compose_file', $data) ? null : $site->compose_file)) : null,
                'public_services' => $public === [] ? null : $public,
                'app_port' => $public[0]['host_port'] ?? null,
            ]);
            $changed = array_keys($site->getDirty());
            $site->save();

            if ($version !== null && $version !== (int) $before) {
                $changed[] = 'compose_content';
            }

            return $changed;
        });

        if ($changed !== []) {
            $this->audit->record('site.compose_updated', 'site', $site->id, ['changed' => $changed, 'version' => $version], $site->organization_id);
            SiteUpdated::dispatch($site->id, $site->organization_id, $changed, $site->serverIds());
        }

        return ['changed' => $changed, 'version' => $version];
    }
}
