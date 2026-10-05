<?php

use Falak\Templates\Domain\Generator;

it('generates values of the requested shape from the CSPRNG', function () {
    expect(Generator::parse('secret(32)')->generate())->toMatch('/^[A-Za-z0-9]{32}$/')
        ->and(Generator::parse('hex(64)')->generate())->toMatch('/^[0-9a-f]{64}$/')
        ->and(Generator::parse('hex(9)')->generate())->toMatch('/^[0-9a-f]{9}$/')
        ->and(Generator::parse('uuid')->generate())->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');

    foreach (range(1, 20) as $ignored) {
        $password = Generator::parse('password(12)')->generate();
        expect($password)->toMatch('/^[A-HJ-NP-Za-km-z2-9]{12}$/')
            ->toMatch('/[A-Z]/')->toMatch('/[a-z]/')->toMatch('/[0-9]/');
    }
});

it('never repeats values', function () {
    $generator = Generator::parse('secret(16)');
    $values = array_map(fn () => $generator->generate(), range(1, 200));

    expect(array_unique($values))->toHaveCount(200);
});

it('rejects unknown functions and out-of-range lengths', function (string $spec) {
    Generator::parse($spec);
})->throws(InvalidArgumentException::class)->with(['random(10)', 'secret()', 'secret(7)', 'hex(129)', 'uuid(4)', '']);
