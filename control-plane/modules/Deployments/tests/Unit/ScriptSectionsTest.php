<?php

use Kiln\Deployments\Application\Planning\ScriptSections;

it('splits a script at its macro lines', function () {
    $sections = ScriptSections::parse("echo a\n\$KILN_FETCH\ncd \$KILN_RELEASE_DIR\nphp artisan migrate\n\${KILN_ACTIVATE}\necho b\n\$KILN_RESTART_PROCS # restart\necho c\n");

    expect($sections->beforeFetch)->toBe("echo a\n")
        ->and($sections->beforeActivate)->toBe("cd \$KILN_RELEASE_DIR\nphp artisan migrate\n")
        ->and($sections->afterActivate)->toBe("echo b\n")
        ->and($sections->afterRestart)->toBe("echo c\n");
});

it('fetches first and activates last when the macros are missing', function () {
    $sections = ScriptSections::parse("composer install\nphp artisan migrate\n");

    expect($sections->beforeFetch)->toBe('')
        ->and($sections->beforeActivate)->toBe("composer install\nphp artisan migrate\n")
        ->and($sections->afterActivate)->toBe('')
        ->and($sections->afterRestart)->toBe('');
});

it('restarts right after activation when $KILN_RESTART_PROCS is missing', function () {
    $sections = ScriptSections::parse("\$KILN_FETCH\n\$KILN_ACTIVATE\necho after\n");

    expect($sections->afterActivate)->toBe("echo after\n")->and($sections->afterRestart)->toBe('');
});

it('treats comment-only sections as empty and keeps inline macro uses', function () {
    $sections = ScriptSections::parse("# setup\n\$KILN_FETCH\necho \$KILN_FETCH done\n\$KILN_ACTIVATE\n");

    expect($sections->beforeFetch)->toBe('')->and($sections->beforeActivate)->toBe("echo \$KILN_FETCH done\n");
});

it('rejects repeated or out-of-order macros', function (string $script, string $message) {
    expect(fn () => ScriptSections::parse($script))->toThrow(InvalidArgumentException::class, $message);
})->with([
    ["\$KILN_FETCH\n\$KILN_FETCH\n", 'more than once'],
    ["\$KILN_ACTIVATE\n\$KILN_FETCH\n", '$KILN_FETCH must come before $KILN_ACTIVATE'],
    ["\$KILN_RESTART_PROCS\n\$KILN_ACTIVATE\n", '$KILN_ACTIVATE must come before $KILN_RESTART_PROCS'],
]);
