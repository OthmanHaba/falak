<?php

namespace Kiln\Templates\Application\Console;

use Illuminate\Console\Command;
use Kiln\Templates\Application\Catalog\Catalog;
use Kiln\Templates\Application\Catalog\TemplateValidator;
use Kiln\Templates\Application\Compose\KilnPlaceholders;
use Kiln\Templates\Application\Inputs\InputResolver;
use Kiln\Templates\Domain\InputType;
use Kiln\Templates\Domain\TemplateInput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Template authoring aid: validate a catalog template and print its compose file as Kiln would render it (test
 * domains under --domain), optionally with a dotenv of sample input values — pipe into
 * `docker compose -f - --env-file <file> config` to check that Compose accepts it.
 */
final class RenderTemplateCommand extends Command
{
    protected $signature = 'templates:render {slug : catalog template} {--domain=kiln.test : test domain base} {--env-file= : write sample input values to this dotenv file}';

    protected $description = 'Validate a catalog template and print its rendered compose file';

    public function handle(Catalog $catalog, TemplateValidator $validator, InputResolver $inputs): int
    {
        $template = $catalog->find((string) $this->argument('slug'));

        if ($template === null) {
            $this->error('Unknown catalog template (or it does not parse — check the logs).');

            return self::FAILURE;
        }

        $problems = $validator->problems($template);

        foreach ($problems as $problem) {
            $this->output->getErrorStyle()->writeln("<error>{$problem}</error>");
        }

        $base = trim((string) $this->option('domain'), '.');
        $domains = [];

        foreach ($template->publicServices() as $index => $service) {
            $domains[$service] = $index === 0 ? "{$template->slug}.{$base}" : "{$service}-{$template->slug}.{$base}";
        }

        $this->output->write(KilnPlaceholders::render($template->composeYaml, $domains, $template->slug), false, OutputInterface::OUTPUT_RAW);

        if ($file = $this->option('env-file')) {
            $sample = array_map(fn (TemplateInput $input) => match (true) {
                $input->default !== null || $input->generate !== null || ! $input->required => null,
                $input->type === InputType::Email => 'admin@example.com',
                $input->type === InputType::Number => '1',
                $input->type === InputType::Domain => "mail.{$base}",
                default => 'sample',
            }, array_combine($template->inputKeys(), $template->inputs) ?: []);
            $values = $inputs->resolve($template, array_filter($sample, fn ($value) => $value !== null));
            $lines = array_map(fn (string $key, string $value) => $key.'='.escapeshellarg(KilnPlaceholders::render($value, $domains, $template->slug)), array_keys($values), $values);
            file_put_contents((string) $file, implode("\n", $lines)."\n");
        }

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
