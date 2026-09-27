<?php

namespace Kiln\Templates\Application\Catalog;

use Illuminate\Support\Facades\Log;
use Kiln\Templates\Domain\InvalidTemplate;
use Kiln\Templates\Domain\Models\CustomTemplate;
use Kiln\Templates\Domain\Template;
use Kiln\Templates\Domain\TemplateSource;

/**
 * Templates an organization can deploy: the curated catalog plus its own custom templates.
 */
final class TemplateRepository
{
    public function __construct(
        private readonly Catalog $catalog,
        private readonly TemplateParser $parser,
    ) {}

    /**
     * @return list<Template>
     */
    public function catalog(): array
    {
        return $this->catalog->all();
    }

    /**
     * @return list<Template>
     */
    public function custom(string $organizationId): array
    {
        return CustomTemplate::query()->where('organization_id', $organizationId)->orderBy('name')->get()
            ->map(fn (CustomTemplate $row) => $this->fromModel($row))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * A template by slug. Without an explicit source the catalog wins over a custom template of the same slug.
     */
    public function find(string $organizationId, string $slug, ?TemplateSource $source = null): ?Template
    {
        if ($source !== TemplateSource::Custom && ($template = $this->catalog->find($slug)) !== null) {
            return $template;
        }

        if ($source === TemplateSource::Catalog) {
            return null;
        }

        $row = CustomTemplate::query()->where('organization_id', $organizationId)->where('slug', $slug)->first();

        return $row !== null ? $this->fromModel($row) : null;
    }

    public function fromModel(CustomTemplate $row): ?Template
    {
        try {
            return $this->parser->parse($row->template_yaml, $row->compose_yaml)->withSource(TemplateSource::Custom, $row->id);
        } catch (InvalidTemplate $e) {
            Log::warning("Custom template {$row->id} no longer parses: {$e->getMessage()}");

            return null;
        }
    }
}
