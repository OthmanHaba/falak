<?php

use Kiln\Templates\Application\Catalog\Catalog;
use Kiln\Templates\Application\Catalog\TemplateParser;
use Kiln\Templates\Application\Catalog\TemplateValidator;
use Kiln\Templates\Application\Compose\ComposeDocument;
use Kiln\Templates\Infrastructure\FilesystemCatalog;

/*
 * The curated catalog (templates/ at the repository root, docs/COMPOSE_TEMPLATES.md §2.1): every template must
 * pass the same validation as an import, plus the catalog's own quality bar.
 */

const CATALOG_V1 = [
    'appsmith', 'directus', 'ghost', 'gitea', 'grafana', 'listmonk', 'mailpit', 'meilisearch', 'metabase',
    'minio', 'n8n', 'nocodb', 'plausible', 'redis-stack', 'umami', 'uptime-kuma', 'vaultwarden', 'wordpress',
];

/** Brand icons the UI ships (modules/Templates/resources/js/components/template-icon.tsx). */
const CATALOG_BRAND_ICONS = [
    'appsmith', 'clickhouse', 'directus', 'docker', 'ghost', 'gitea', 'grafana', 'listmonk', 'mariadb', 'meilisearch', 'metabase',
    'minio', 'mysql', 'n8n', 'plausibleanalytics', 'postgresql', 'redis', 'umami', 'uptimekuma', 'vaultwarden', 'wordpress',
];

function catalog_files(): array
{
    return (new FilesystemCatalog(cache()->store(), new TemplateParser, base_path('../templates')))->files();
}

it('ships catalog v1', function () {
    expect(array_keys(catalog_files()))->toBe(CATALOG_V1)
        ->and(array_map(fn ($t) => $t->slug, app(Catalog::class)->all()))->toEqualCanonicalizing(CATALOG_V1);
});

it('validates every catalog template', function (string $slug) {
    $files = catalog_files()[$slug];
    $template = (new TemplateParser)->parse($files['template'], $files['compose']);

    expect($template->slug)->toBe($slug)
        ->and(app(TemplateValidator::class)->problems($template))->toBe([]);

    $compose = ComposeDocument::parse($files['compose']);
    $namedVolumes = array_keys((array) ($compose->data['volumes'] ?? []));

    foreach ($compose->services() as $name => $service) {
        expect(array_key_exists('healthcheck', $service))->toBeTrue("{$slug}: services.{$name} needs a healthcheck")
            ->and($service['restart'] ?? null)->toBe('unless-stopped', "{$slug}: services.{$name} should restart unless-stopped");

        foreach ((array) ($service['volumes'] ?? []) as $volume) {
            $source = explode(':', (string) $volume)[0];
            expect(in_array($source, $namedVolumes, true))->toBeTrue("{$slug}: {$name} mounts {$source}, which is not a named volume");
        }
    }

    if ($template->stateful) {
        expect($namedVolumes)->not->toBe([], "{$slug} is stateful but keeps no data in named volumes");
    }

    expect($template->minMemoryMb)->not->toBeNull("{$slug}: set min_memory_mb")
        ->and($template->docs)->not->toBeNull("{$slug}: link the docs")
        ->and(mb_strlen($template->description))->toBeLessThanOrEqual(120, "{$slug}: keep the description to one line");

    if ($template->icon === './icon.svg') {
        expect($files['icon'])->not->toBeNull("{$slug}: icon.svg is missing")
            ->and(file_get_contents((string) $files['icon']))->not->toMatch('/<script|on[a-z]+=|href=/i');
    } else {
        expect(in_array($template->icon, CATALOG_BRAND_ICONS, true))->toBeTrue("{$slug}: the UI has no brand icon {$template->icon}");
    }
})->with(CATALOG_V1);
