<?php

use Kiln\Fleet\Infrastructure\ProtocolSchemas;

beforeEach(fn () => $this->schemas = app(ProtocolSchemas::class));

it('reads the shared contracts directory', function () {
    expect(is_file($this->schemas->path().'/envelope.schema.json'))->toBeTrue()
        ->and($this->schemas->hasCommand('provision.apply'))->toBeTrue()
        ->and($this->schemas->hasCommand('system.ssh_key.sync'))->toBeTrue()
        ->and($this->schemas->hasCommand('nope.nope'))->toBeFalse()
        ->and($this->schemas->hasCommand('../../etc/passwd'))->toBeFalse();
});

it('resolves cross-file $refs (heartbeat → facts)', function () {
    $errors = $this->schemas->validate('heartbeat.schema.json', ProtocolSchemas::toJson([
        'at' => '2026-09-26T10:00:00Z', 'uptime_s' => 1, 'load' => [0, 0, 0], 'memory_used_bytes' => 1, 'disk_used_bytes' => 1,
        'facts' => ['hostname' => 'x'],
    ]));

    expect($errors)->toHaveKey('/facts');
});

it('validates command results against $defs/result', function () {
    expect($this->schemas->validateCommandResult('system.ssh_key.sync', ProtocolSchemas::toJson(['changed' => true, 'count' => 2])))->toBe([])
        ->and($this->schemas->validateCommandResult('system.ssh_key.sync', ProtocolSchemas::toJson(['changed' => true])))->not->toBe([]);
});

it('maps an empty PHP array to a JSON object', function () {
    expect(ProtocolSchemas::toJson([]))->toBeInstanceOf(stdClass::class)
        ->and($this->schemas->validateCommand('system.facts', []))->toBe([]);
});
