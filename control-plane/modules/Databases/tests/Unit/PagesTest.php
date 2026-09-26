<?php

it('ships every Inertia page the controllers render', function (string $page) {
    expect(dirname(__DIR__, 2)."/resources/js/pages/{$page}.tsx")->toBeFile();
})->with(['Index', 'Show', 'Backups', 'Storage']);
