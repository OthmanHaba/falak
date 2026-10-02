<?php

use Kiln\Sites\Application\Compose\ComposeProject;
use Kiln\Sites\Application\Compose\ComposeProjectException;

/**
 * kiln-builder loads projects with the same rules (agent/internal/builder/composeproject.go).
 */
$cases = json_decode((string) file_get_contents(dirname(__DIR__, 5).'/contracts/compose/merge-cases.json'), true)['cases'];

function compose_canonical(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    $value = array_map('compose_canonical', $value);

    if (! array_is_list($value)) {
        ksort($value);
    }

    return $value;
}

it('loads compose projects like the builder', function (array $case) {
    $read = fn (string $path): ?string => $case['repo'][$path] ?? null;

    if (isset($case['error'])) {
        expect(fn () => ComposeProject::load($read, $case['files'], $case['profiles']))
            ->toThrow(ComposeProjectException::class, $case['error']);

        return;
    }

    $project = ComposeProject::load($read, $case['files'], $case['profiles']);

    expect(compose_canonical(json_decode((string) json_encode($project['doc']), true)))->toBe(compose_canonical($case['expected']))
        ->and(ComposeProject::references($project['doc']))->toBe($case['references'] ?? []);
})->with(array_combine(array_column($cases, 'name'), array_map(fn ($c) => [$c], $cases)));
