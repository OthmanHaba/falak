<?php

use Falak\Templates\Application\Catalog\TemplateParser;
use Falak\Templates\Application\Inputs\InputResolver;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Support/helpers.php';

function fixture_template()
{
    return (new TemplateParser)->parse(TEMPLATES_FIXTURE_TEMPLATE, TEMPLATES_FIXTURE_COMPOSE);
}

it('fills generated values, defaults and the user input', function () {
    $values = (new InputResolver)->resolve(fixture_template(), ['ADMIN_EMAIL' => ' ops@example.com ', 'SIGNUPS' => true, 'TIMEZONE' => 'Europe/Berlin']);

    expect(array_keys($values))->toBe(['APP_SECRET', 'DB_PASSWORD', 'ADMIN_EMAIL', 'TIMEZONE', 'SIGNUPS', 'WORKERS', 'PUBLIC_URL', 'DATABASE_URL'])
        ->and($values['APP_SECRET'])->toMatch('/^[A-Za-z0-9]{32}$/')
        ->and($values['DB_PASSWORD'])->toHaveLength(20)
        ->and($values['ADMIN_EMAIL'])->toBe('ops@example.com')
        ->and($values['TIMEZONE'])->toBe('Europe/Berlin')
        ->and($values['SIGNUPS'])->toBe('true')
        ->and($values['WORKERS'])->toBe('2')
        ->and($values['PUBLIC_URL'])->toBe('${{ falak.url(web) }}')
        ->and($values['DATABASE_URL'])->toBe('${{ postgres.DATABASE_URL }}');
});

it('keeps generated values the form sends back (generated once)', function () {
    $values = (new InputResolver)->resolve(fixture_template(), ['ADMIN_EMAIL' => 'a@b.co', 'APP_SECRET' => 'shown-in-the-form-and-kept']);

    expect($values['APP_SECRET'])->toBe('shown-in-the-form-and-kept');
});

it('validates types and required inputs', function (array $given, string $key, string $message) {
    try {
        (new InputResolver)->resolve(fixture_template(), [...['ADMIN_EMAIL' => 'a@b.co'], ...$given]);
        $this->fail('no validation error');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey("inputs.{$key}")
            ->and($e->errors()["inputs.{$key}"][0])->toContain($message);
    }
})->with([
    'required' => [['ADMIN_EMAIL' => ''], 'ADMIN_EMAIL', 'is required'],
    'email' => [['ADMIN_EMAIL' => 'nope'], 'ADMIN_EMAIL', 'email address'],
    'select' => [['TIMEZONE' => 'Mars/Base'], 'TIMEZONE', 'must be one of'],
    'number' => [['WORKERS' => 'many'], 'WORKERS', 'must be a number'],
    'boolean' => [['SIGNUPS' => 'maybe'], 'SIGNUPS', 'true or false'],
    'multiline' => [['APP_SECRET' => "a\nb"], 'APP_SECRET', 'single line'],
]);

it('generates fresh values for the configure form', function () {
    $resolver = new InputResolver;
    $a = $resolver->generated(fixture_template());
    $b = $resolver->generated(fixture_template());

    expect(array_keys($a))->toBe(['APP_SECRET', 'DB_PASSWORD'])
        ->and($a['APP_SECRET'])->not->toBe($b['APP_SECRET']);
});
