<?php

namespace Falak\Templates\Domain;

use Illuminate\Support\Str;

/**
 * One input of a template: becomes a site variable of the same key.
 */
final readonly class TemplateInput
{
    /**
     * @param  list<string>  $options  select choices
     */
    public function __construct(
        public string $key,
        public InputType $type,
        public string $label,
        public ?string $description = null,
        public ?string $default = null,
        public ?Generator $generate = null,
        public array $options = [],
        public bool $required = true,
        public ?string $placeholder = null,
    ) {}

    public static function labelFor(string $key): string
    {
        return Str::ucfirst(strtolower(str_replace('_', ' ', $key)));
    }

    public function isGenerated(): bool
    {
        return $this->generate !== null;
    }

    public function isSecret(): bool
    {
        return $this->type->isSecret();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'key' => $this->key,
            'type' => $this->type->value,
            'label' => $this->label,
            'description' => $this->description,
            'default' => $this->default,
            'generate' => $this->generate !== null ? (string) $this->generate : null,
            'options' => $this->options === [] ? null : $this->options,
            'required' => $this->required,
            'placeholder' => $this->placeholder,
            'secret' => $this->isSecret(),
        ], fn ($value) => $value !== null);
    }
}
