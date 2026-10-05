<?php

use Falak\Sites\Contracts\Data\EnvironmentData;
use Falak\Sites\Contracts\DeployScript;
use Falak\Sites\Domain\Dotenv;

it('parses dotenv files', function () {
    expect(Dotenv::parse("A=1\n\n# comment\nexport B = two words # note\nC='single \$x'\nD=\"a\\\"b\\nc\"\nE=\nA=override"))
        ->toBe(['A' => 'override', 'B' => 'two words', 'C' => 'single $x', 'D' => "a\"b\nc", 'E' => '']);
});

it('rejects malformed lines', function (string $content) {
    Dotenv::parse($content);
})->throws(InvalidArgumentException::class)->with(['NOEQUALS', '9A=1', 'A-B=1', "A='open"]);

it('renders values so they parse back identically', function () {
    $variables = ['PLAIN' => 'abc', 'SPACES' => 'a b', 'QUOTES' => 'say "hi"', 'DOLLAR' => 'pa$$', 'NEWLINE' => "x\ny", 'BACKSLASH' => 'a\\b', 'EMPTY' => '', 'URL' => 'https://x.test/a?b=c'];
    $env = new EnvironmentData('site', 1, $variables, []);

    expect(Dotenv::parse($env->toDotenv()))->toBe($variables);
});

it('finds macros in deploy scripts', function () {
    expect(DeployScript::macrosIn("\$FALAK_FETCH\n\${FALAK_ACTIVATE}\necho \$FALAK_RESTART_PROCSX"))->toBe(['FALAK_FETCH', 'FALAK_ACTIVATE']);
});
