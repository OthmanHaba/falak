<?php

namespace Falak\Templates\Application\Actions;

use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Templates\Application\Catalog\Catalog;
use Falak\Templates\Application\Catalog\TemplateParser;
use Falak\Templates\Application\Catalog\TemplateValidator;
use Falak\Templates\Application\Compose\ComposeAnalyzer;
use Falak\Templates\Application\Compose\ComposeDocument;
use Falak\Templates\Application\Compose\FalakPlaceholders;
use Falak\Templates\Application\Compose\SiteCompose;
use Falak\Templates\Domain\InvalidTemplate;
use Falak\Templates\Domain\Models\CustomTemplate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Yaml;

/**
 * "Save as template" (docs/COMPOSE_TEMPLATES.md §3): turn an inline compose site into a template draft the user
 * reviews before saving. Secret values never leave the site — they become generated inputs; the site's domains
 * become Falak placeholders; other variables become inputs with their current value as default.
 */
final class DraftTemplateFromSite
{
    private const SECRET_KEY = '/(SECRET|PASSWORD|PASSWD|PASS|PWD|TOKEN|KEY|SALT|PRIVATE|CREDENTIAL|AUTH|DSN|DATABASE_URL)/';

    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly SiteCompose $compose,
        private readonly ComposeAnalyzer $analyzer,
        private readonly TemplateParser $parser,
        private readonly TemplateValidator $validator,
        private readonly Catalog $catalog,
    ) {}

    /**
     * @return array{template_yaml: string, compose_yaml: string, problems: list<string>}
     *
     * @throws ValidationException
     */
    public function __invoke(SiteData $site): array
    {
        $content = $this->compose->content($site)
            ?? throw ValidationException::withMessages(['site' => 'Only compose sites whose compose file is stored in Falak (inline) can be saved as templates.']);

        try {
            $facts = $this->analyzer->analyze($content);
            ComposeDocument::parse($content);
        } catch (InvalidTemplate $e) {
            throw ValidationException::withMessages(['site' => $e->errors]);
        }

        $public = $this->compose->publicServices($site);

        if ($public === []) {
            $first = array_key_first(array_filter($facts->services, fn (array $ports) => $ports !== []));
            $public = $first !== null ? [['service' => (string) $first, 'port' => $facts->services[$first][0], 'domain' => null]] : [];
        }

        // Domains the site is served on → Falak placeholders (longest first so sub-domains are not split).
        $replacements = [];
        foreach ($public as $index => $entry) {
            $domain = $entry['domain'] ?? $this->testDomain($site, $entry['service'], $index);

            if ($domain !== null) {
                $replacements["https://{$domain}"] = "\${{ falak.url({$entry['service']}) }}";
                $replacements["http://{$domain}"] = "\${{ falak.url({$entry['service']}) }}";
                $replacements[$domain] = "\${{ falak.domain({$entry['service']}) }}";
            }
        }
        uksort($replacements, fn (string $a, string $b) => strlen($b) <=> strlen($a));
        $placeholders = fn (string $text) => strtr($text, $replacements);

        $composeYaml = $placeholders($content);
        $variables = $this->sites->environment($site->id)?->variables ?? [];
        $referenced = array_column(ComposeDocument::parse($composeYaml)->variables(), 'name');
        $inputs = [];

        foreach ($variables as $key => $value) {
            if (str_starts_with($key, 'FALAK_') || preg_match(TemplateParser::KEY_PATTERN, $key) !== 1) {
                continue;
            }

            $inputs[] = $this->input($key, $placeholders((string) $value));
        }

        foreach ($referenced as $key) {
            if (! array_key_exists($key, $variables) && ! in_array($key, FalakPlaceholders::RUNTIME_VARIABLES, true)) {
                $inputs[] = ['key' => $key, 'type' => 'string', 'label' => Str::headline(strtolower($key))];
            }
        }

        $slug = Str::limit(Str::slug($site->name), 40, '') ?: 'template';
        if ($this->catalog->find($slug) !== null || CustomTemplate::query()->where('organization_id', $site->organizationId)->where('slug', $slug)->exists()) {
            $slug .= '-custom';
        }

        $templateYaml = Yaml::dump(array_filter([
            'name' => mb_substr($site->name, 0, 60),
            'slug' => $slug,
            'version' => '1.0.0',
            'description' => mb_substr("Created from {$site->name}.", 0, 300),
            'category' => 'dev-tools',
            'icon' => 'docker',
            'stateful' => $facts->volumes !== [],
            'public' => array_map(fn (array $entry) => ['service' => $entry['service'], 'port' => $entry['port']], $public),
            'inputs' => $inputs,
        ], fn ($value) => $value !== [] && $value !== null), 6, 2);

        try {
            $problems = $this->validator->problems($this->parser->parse($templateYaml, $composeYaml));
        } catch (InvalidTemplate $e) {
            $problems = $e->errors;
        }

        return ['template_yaml' => $templateYaml, 'compose_yaml' => $composeYaml, 'problems' => $problems];
    }

    /**
     * @return array<string, mixed>
     */
    private function input(string $key, string $value): array
    {
        $label = Str::headline(strtolower($key));

        if (str_contains($value, '${{') && ! str_contains($value, '${{ falak.')) {
            // A reference to another service's variable: keep it (resolved at deploy time).
            return ['key' => $key, 'type' => 'string', 'label' => $label, 'default' => $value];
        }

        if (preg_match(self::SECRET_KEY, $key) === 1 || self::looksRandom($value)) {
            return ['key' => $key, 'type' => 'secret', 'label' => $label, 'generate' => self::generatorFor($key, $value)];
        }

        $type = match (true) {
            in_array(strtolower($value), ['true', 'false'], true) => 'boolean',
            filter_var($value, FILTER_VALIDATE_EMAIL) !== false => 'email',
            default => 'string',
        };

        return array_filter(['key' => $key, 'type' => $type, 'label' => $label, 'default' => $type === 'boolean' ? strtolower($value) === 'true' : $value, 'required' => $value === '' ? false : null], fn ($v) => $v !== null && $v !== '');
    }

    private static function generatorFor(string $key, string $value): string
    {
        $length = max(16, min(64, strlen($value)));

        return match (true) {
            preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1 => 'uuid',
            preg_match('/^[0-9a-f]{16,}$/i', $value) === 1 => 'hex('.min(128, strlen($value)).')',
            preg_match('/PASS|PWD/', $key) === 1 => "password({$length})",
            default => "secret({$length})",
        };
    }

    private static function looksRandom(string $value): bool
    {
        return strlen($value) >= 20
            && preg_match('/^[A-Za-z0-9+\/=_.-]+$/', $value) === 1
            && preg_match('/[0-9]/', $value) === 1
            && preg_match('/[A-Za-z]/', $value) === 1
            && ! str_contains($value, '.');
    }

    private function testDomain(SiteData $site, string $service, int $index): ?string
    {
        $base = DeployTemplate::testDomainBase();

        if ($base === null) {
            return null;
        }

        return $index === 0 ? ($site->testDomain ?? "{$site->slug}.{$base}") : "{$service}-{$site->slug}.{$base}";
    }
}
