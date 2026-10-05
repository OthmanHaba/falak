<?php

namespace Falak\Templates\Application\Catalog;

use Falak\Templates\Application\Compose\ComposeAnalyzer;
use Falak\Templates\Application\Compose\ComposeDocument;
use Falak\Templates\Application\Compose\FalakPlaceholders;
use Falak\Templates\Domain\InvalidTemplate;
use Falak\Templates\Domain\Template;

/**
 * Whether a template's compose file satisfies it (docs/COMPOSE_TEMPLATES.md §2 "Validation"): the compose file
 * parses; every `${VAR}` is an input or a Falak variable; `${{ … }}` placeholders are valid; public services exist
 * and expose their port; images are pinned; no `build:`; and the compose runtime's policy passes.
 */
final class TemplateValidator
{
    public function __construct(private readonly ComposeAnalyzer $analyzer) {}

    /**
     * @return list<string> problems (empty = valid)
     */
    public function problems(Template $template): array
    {
        try {
            $compose = ComposeDocument::parse($template->composeYaml);
        } catch (InvalidTemplate $e) {
            return $e->errors;
        }

        $errors = [];
        $inputs = $template->inputKeys();
        $publicServices = $template->publicServices();

        foreach ($compose->services() as $name => $service) {
            $at = "compose.yaml: services.{$name}";

            if (array_key_exists('build', $service)) {
                $errors[] = "{$at}.build: templates cannot build images; reference a published image";
            }

            $image = $service['image'] ?? null;

            if (! is_string($image) || trim($image) === '') {
                $errors[] = "{$at}.image is required";
            } elseif (($problem = self::imageProblem($image)) !== null) {
                $errors[] = "{$at}.image {$problem}";
            }

            if (($service['container_name'] ?? null) !== null) {
                $errors[] = "{$at}.container_name: remove it (Falak names containers per site so a template can be deployed twice)";
            }
        }

        foreach ($compose->variables() as $variable) {
            if (! $variable['optional'] && ! in_array($variable['name'], $inputs, true) && ! in_array($variable['name'], FalakPlaceholders::RUNTIME_VARIABLES, true)) {
                $errors[] = "compose.yaml: \${{$variable['name']}} is neither an input nor a Falak variable";
            }
        }

        foreach ($compose->placeholders() as $expression) {
            if (($problem = FalakPlaceholders::problem($expression, $publicServices)) !== null) {
                $errors[] = "compose.yaml: {$problem}";
            }
        }

        foreach ($template->inputs as $input) {
            foreach ([$input->default] as $value) {
                foreach (FalakPlaceholders::find((string) $value) as [$function, $service]) {
                    if ($service !== null && ! in_array($service, $publicServices, true)) {
                        $errors[] = "template.yaml: inputs.{$input->key}.default uses falak.{$function}({$service}) but {$service} is not a public service";
                    }
                }
            }
        }

        try {
            $facts = $this->analyzer->analyze($template->composeYaml);
        } catch (InvalidTemplate $e) {
            return [...$errors, ...$e->errors];
        }

        foreach ($template->public as $public) {
            $service = $public['service'];

            if (! array_key_exists($service, $facts->services)) {
                $errors[] = "template.yaml: public service {$service} is not a service in compose.yaml";
            } elseif (! in_array($public['port'], $facts->services[$service], true) && ! in_array($public['port'], $compose->containerPorts($service), true)) {
                $errors[] = "compose.yaml: services.{$service} must expose port {$public['port']} (add `expose: [\"{$public['port']}\"]`)";
            }
        }

        foreach ($facts->violations as $violation) {
            $errors[] = "compose.yaml: {$violation}";
        }

        return array_values(array_unique($errors));
    }

    /**
     * @throws InvalidTemplate
     */
    public function assertValid(Template $template): void
    {
        $problems = $this->problems($template);

        if ($problems !== []) {
            throw new InvalidTemplate($problems, "Template {$template->slug} is invalid");
        }
    }

    /** Null when the image reference is pinned (a tag other than `latest`, or a digest). */
    public static function imageProblem(string $image): ?string
    {
        if (str_contains($image, '${')) {
            return 'must not be interpolated (pin the image in the template)';
        }

        if (preg_match('/@sha256:[0-9a-f]{64}$/', $image) === 1) {
            return null;
        }

        // The tag is after the last ':' that follows the last '/' (a registry host may carry a port).
        $name = substr($image, (int) strrpos('/'.$image, '/'));
        $tag = str_contains($name, ':') ? substr($name, strrpos($name, ':') + 1) : null;

        if ($tag === null || $tag === '') {
            return "{$image} must be pinned to a version tag";
        }

        if (strtolower($tag) === 'latest') {
            return "{$image} must be pinned to a version (not latest)";
        }

        return null;
    }
}
