<?php

use Kiln\Recipes\Infrastructure\BuiltinRecipes;

it('ships well-formed built-in recipes', function () {
    $all = (new BuiltinRecipes)->all();

    expect(count($all))->toBeGreaterThanOrEqual(5)
        ->and(array_keys($all))->toContain('clear-caches', 'install-package', 'disk-usage');

    foreach ($all as $key => $recipe) {
        expect($recipe->key)->toBe($key)
            ->and($key)->toMatch('/^[a-z0-9-]+$/')
            ->and(trim($recipe->script))->not->toBe('')
            ->and($recipe->user)->toMatch('/^[a-z_][a-z0-9_-]{0,31}$/');

        foreach (array_keys($recipe->variables) as $name) {
            expect($name)->toMatch('/^[A-Za-z_][A-Za-z0-9_]*$/')
                ->and($recipe->script)->toContain('$'.$name);
        }
    }
});

it('validates package names inside the install-package script', function () {
    $script = (new BuiltinRecipes)->find('install-package')->script;

    $run = function (string $package) use ($script): int {
        $check = explode('export DEBIAN_FRONTEND', $script)[0].'echo ok';
        $process = proc_open(['bash', '-c', $check], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PACKAGE' => $package, 'PATH' => getenv('PATH')]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);

        return proc_close($process);
    };

    expect($run('htop ncdu'))->toBe(0)
        ->and($run('libc6:amd64'))->toBe(0)
        ->and($run('htop; rm -rf /'))->toBe(2)
        ->and($run(''))->toBe(2);
});

it('ships every Inertia page the controllers render', function (string $page) {
    expect(dirname(__DIR__, 2)."/resources/js/pages/{$page}.tsx")->toBeFile();
})->with(['Index', 'Run', 'RunShow', 'History']);
