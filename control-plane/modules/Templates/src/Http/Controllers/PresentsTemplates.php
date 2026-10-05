<?php

namespace Falak\Templates\Http\Controllers;

use Falak\Templates\Application\Compose\ComposeDocument;
use Falak\Templates\Domain\Category;
use Falak\Templates\Domain\InvalidTemplate;
use Falak\Templates\Domain\Template;
use Falak\Templates\Domain\TemplateInput;
use Falak\Templates\Domain\TemplateSource;

trait PresentsTemplates
{
    /**
     * @return array<string, mixed>
     */
    protected function summary(Template $template): array
    {
        try {
            $compose = ComposeDocument::parse($template->composeYaml);
            $services = array_map(fn (string $name) => ['name' => $name, 'image' => (string) ($compose->service($name)['image'] ?? '')], $compose->serviceNames());
        } catch (InvalidTemplate) {
            $services = [];
        }

        return [
            'slug' => $template->slug,
            'id' => $template->id,
            'source' => $template->source->value,
            'name' => $template->name,
            'description' => $template->description,
            'version' => $template->version,
            'category' => $template->category->value,
            'category_label' => $template->category->label(),
            'icon' => $this->iconOf($template),
            'docs' => $template->docs,
            'popular' => $template->popular,
            'stateful' => $template->stateful,
            'min_memory_mb' => $template->minMemoryMb,
            'tags' => $template->tags,
            'services' => $services,
            'public' => $template->public,
        ];
    }

    /**
     * @param  array<string, string>  $generated
     * @return array<string, mixed>
     */
    protected function detail(Template $template, array $generated, ?string $testDomain): array
    {
        return [
            ...$this->summary($template),
            'inputs' => array_map(fn (TemplateInput $input) => [...$input->toArray(), 'generated' => $input->isGenerated()], $template->inputs),
            'generated' => $generated,
            'compose' => $template->composeYaml,
            'template_yaml' => $template->templateYaml,
            'test_domain' => $testDomain,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    protected function categories(): array
    {
        return array_map(fn (Category $category) => ['value' => $category->value, 'label' => $category->label()], Category::cases());
    }

    /**
     * @return array{type: 'brand', key: string}|array{type: 'url', url: string}|null
     */
    private function iconOf(Template $template): ?array
    {
        if ($template->icon === './icon.svg') {
            return $template->source === TemplateSource::Catalog && $template->iconPath !== null
                ? ['type' => 'url', 'url' => "/templates/catalog/{$template->slug}/icon.svg"]
                : null;
        }

        return $template->icon !== null ? ['type' => 'brand', 'key' => $template->icon] : null;
    }
}
