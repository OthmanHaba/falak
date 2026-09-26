<?php

namespace Kiln\Sites\Contracts\Data;

/**
 * One version of a site's environment. Contains secrets: never log it or send it to the UI.
 */
final readonly class EnvironmentData
{
    /**
     * @param  array<string, string>  $variables
     * @param  list<string>  $exposedToDeployScript  keys exported into the deploy script environment
     */
    public function __construct(
        public string $siteId,
        public int $version,
        public array $variables,
        public array $exposedToDeployScript,
    ) {}

    /** Rendered .env file contents. */
    public function toDotenv(): string
    {
        $lines = [];

        foreach ($this->variables as $key => $value) {
            $lines[] = $key.'='.self::quote($value);
        }

        return $lines === [] ? '' : implode("\n", $lines)."\n";
    }

    /**
     * @return array<string, string>
     */
    public function deployScriptVariables(): array
    {
        return array_intersect_key($this->variables, array_flip($this->exposedToDeployScript));
    }

    public static function quote(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_.,:\/@+=\-]*$/', $value) === 1) {
            return $value;
        }

        return '"'.str_replace(['\\', '"', "\n", '$'], ['\\\\', '\\"', '\\n', '\\$'], $value).'"';
    }
}
