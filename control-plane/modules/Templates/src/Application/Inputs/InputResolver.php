<?php

namespace Falak\Templates\Application\Inputs;

use Illuminate\Validation\ValidationException;
use Falak\Templates\Domain\InputType;
use Falak\Templates\Domain\Template;
use Falak\Templates\Domain\TemplateInput;

/**
 * The values a site created from a template starts with: what the user entered, else a freshly generated value
 * (`generate:`), else the default. Values are validated against the input type. Errors are keyed `inputs.<KEY>`.
 */
final class InputResolver
{
    public const MAX_VALUE_LENGTH = 4096;

    /**
     * @param  array<string, mixed>  $given
     * @return array<string, string> KEY => value, in template order
     *
     * @throws ValidationException
     */
    public function resolve(Template $template, array $given): array
    {
        $values = [];
        $errors = [];

        foreach ($template->inputs as $input) {
            $raw = $given[$input->key] ?? null;
            $raw = is_bool($raw) ? ($raw ? 'true' : 'false') : (is_scalar($raw) ? trim((string) $raw) : null);

            if ($raw === null || $raw === '') {
                $value = match (true) {
                    $input->generate !== null => $input->generate->generate(),
                    $input->default !== null => $input->default,
                    $input->type === InputType::Boolean => 'false',
                    default => null,
                };

                if ($value === null) {
                    if ($input->required) {
                        $errors["inputs.{$input->key}"] = "{$input->label} is required.";
                    } else {
                        $values[$input->key] = '';
                    }

                    continue;
                }

                $values[$input->key] = $value;

                continue;
            }

            $problem = $this->problem($input, $raw);

            if ($problem !== null) {
                $errors["inputs.{$input->key}"] = $problem;

                continue;
            }

            $values[$input->key] = $input->type === InputType::Boolean ? (in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true) ? 'true' : 'false') : $raw;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $values;
    }

    /**
     * Fresh values for every generated input (the configure form shows them masked, with Show / Regenerate).
     *
     * @return array<string, string>
     */
    public function generated(Template $template): array
    {
        $values = [];

        foreach ($template->inputs as $input) {
            if ($input->generate !== null) {
                $values[$input->key] = $input->generate->generate();
            }
        }

        return $values;
    }

    private function problem(TemplateInput $input, string $value): ?string
    {
        if (strlen($value) > self::MAX_VALUE_LENGTH || str_contains($value, "\0")) {
            return "{$input->label} is too long.";
        }

        return match ($input->type) {
            InputType::Email => filter_var($value, FILTER_VALIDATE_EMAIL) === false ? "{$input->label} must be an email address." : null,
            InputType::Number => is_numeric($value) ? null : "{$input->label} must be a number.",
            InputType::Boolean => in_array(strtolower($value), ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'], true) ? null : "{$input->label} must be true or false.",
            InputType::Select => in_array($value, $input->options, true) ? null : "{$input->label} must be one of: ".implode(', ', $input->options).'.',
            InputType::Domain => self::isDomain($value) ? null : "{$input->label} must be a domain name.",
            InputType::String, InputType::Secret => str_contains($value, "\n") ? "{$input->label} must be a single line." : null,
        };
    }

    public static function isDomain(string $value): bool
    {
        return strlen($value) <= 253
            && preg_match('/^(?=.{1,253}$)(?!-)([a-z0-9-]{1,63}(?<!-)\.)+[a-z][a-z0-9-]{0,62}(?<!-)$/i', $value) === 1;
    }
}
