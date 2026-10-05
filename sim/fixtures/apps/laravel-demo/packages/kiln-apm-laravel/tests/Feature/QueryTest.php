<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    DB::statement('create table posts (id integer primary key, title text, secret text)');

    Route::get('/posts', function () {
        foreach ([1, 2, 3] as $id) {
            DB::select('select * from posts where id = ?', [$id]);
        }

        DB::select("select * from posts where title = 'hunter2'");

        return 'ok';
    });
});

it('records query spans with contract attributes, redacted bindings and N+1 repeat counts', function () {
    $this->get('/posts')->assertOk();

    $root = $this->transport->spansOfType('request')[0];
    $queries = $this->transport->spansOfType('query');

    expect($queries)->toHaveCount(4);

    $repeated = array_values(array_filter($queries, fn ($q) => $q['attrs']['db.query.text'] === 'select * from posts where id = ?'));
    expect($repeated)->toHaveCount(3);

    foreach ($repeated as $query) {
        expect($query['kind'])->toBe(3)
            ->and($query['parentSpanId'])->toBe($root['spanId'])
            ->and($query['traceId'])->toBe($root['traceId'])
            ->and($query['attrs'])->toMatchArray([
                'db.system.name' => 'sqlite',
                'db.namespace' => ':memory:',
                'kiln.query.connection' => 'testing',
                'kiln.query.repeat_count' => 3,
            ]);
    }

    $literal = array_values(array_filter($queries, fn ($q) => str_contains($q['attrs']['db.query.text'], 'title')))[0];
    expect($literal['attrs']['db.query.text'])->toBe('select * from posts where title = ?')
        ->and($literal['attrs']['kiln.query.repeat_count'])->toBe(1);

    expect(json_encode($this->transport->sent))->not->toContain('hunter2');
});
