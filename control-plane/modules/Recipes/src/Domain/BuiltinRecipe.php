<?php

namespace Kiln\Recipes\Domain;

/**
 * A read-only starter recipe shipped with Kiln.
 */
final readonly class BuiltinRecipe
{
    /**
     * @param  array<string, string>  $variables  env var name => description (prefilled in the run form)
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $description,
        public string $script,
        public string $user = 'root',
        public array $variables = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'script' => $this->script,
            'user' => $this->user,
            'variables' => (object) $this->variables,
        ];
    }
}
