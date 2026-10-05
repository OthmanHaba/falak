<?php

use Falak\Deployments\Application\Planning\ScriptSections;

it('splits a script at its macro lines', function () {
    $sections = ScriptSections::parse("echo a\n\$FALAK_FETCH\ncd \$FALAK_RELEASE_DIR\nphp artisan migrate\n\${FALAK_ACTIVATE}\necho b\n\$FALAK_RESTART_PROCS # restart\necho c\n");

    expect($sections->beforeFetch)->toBe("echo a\n")
        ->and($sections->beforeActivate)->toBe("cd \$FALAK_RELEASE_DIR\nphp artisan migrate\n")
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

it('restarts right after activation when $FALAK_RESTART_PROCS is missing', function () {
    $sections = ScriptSections::parse("\$FALAK_FETCH\n\$FALAK_ACTIVATE\necho after\n");

    expect($sections->afterActivate)->toBe("echo after\n")->and($sections->afterRestart)->toBe('');
});

it('treats comment-only sections as empty and keeps inline macro uses', function () {
    $sections = ScriptSections::parse("# setup\n\$FALAK_FETCH\necho \$FALAK_FETCH done\n\$FALAK_ACTIVATE\n");

    expect($sections->beforeFetch)->toBe('')->and($sections->beforeActivate)->toBe("echo \$FALAK_FETCH done\n");
});

it('rejects repeated or out-of-order macros', function (string $script, string $message) {
    expect(fn () => ScriptSections::parse($script))->toThrow(InvalidArgumentException::class, $message);
})->with([
    ["\$FALAK_FETCH\n\$FALAK_FETCH\n", 'more than once'],
    ["\$FALAK_ACTIVATE\n\$FALAK_FETCH\n", '$FALAK_FETCH must come before $FALAK_ACTIVATE'],
    ["\$FALAK_RESTART_PROCS\n\$FALAK_ACTIVATE\n", '$FALAK_ACTIVATE must come before $FALAK_RESTART_PROCS'],
]);
