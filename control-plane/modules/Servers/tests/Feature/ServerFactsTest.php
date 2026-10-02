<?php

use Illuminate\Support\Facades\Event;
use Kiln\Servers\Application\ServerFacts;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Servers\Events\ServerUpdated;

beforeEach(fn () => Event::fake([ServerUpdated::class]));

it('takes the private IPv4 the agent reports', function () {
    $server = Server::factory()->create(['private_ipv4' => null]);

    app(ServerFacts::class)->record($server, ['private_ipv4' => '10.0.0.3']);

    expect($server->refresh()->private_ipv4)->toBe('10.0.0.3');
});

it('clears a private IPv4 the agent reported before when it now reports none', function () {
    // Agents before v0.5.2 reported Docker's bridge address.
    $server = Server::factory()->create(['private_ipv4' => null]);
    app(ServerFacts::class)->record($server, ['private_ipv4' => '172.17.0.1']);

    app(ServerFacts::class)->record($server->refresh(), ['private_ipv4' => null]);

    expect($server->refresh()->private_ipv4)->toBeNull();
});

it('keeps a provider private IPv4 when the agent reports none', function () {
    $server = Server::factory()->create(['provider' => 'hetzner', 'private_ipv4' => '10.0.0.7']);

    app(ServerFacts::class)->record($server, ['private_ipv4' => null]);
    app(ServerFacts::class)->record($server->refresh(), ['hostname' => 'web-1']);

    expect($server->refresh()->private_ipv4)->toBe('10.0.0.7');
});
