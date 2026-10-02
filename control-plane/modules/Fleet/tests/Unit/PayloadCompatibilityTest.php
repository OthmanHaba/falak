<?php

use Kiln\Fleet\Application\PayloadCompatibility;

/*
 * Optional protocol fields are removed for agents that don't report the feature that introduced them.
 */

function compat_swap(): object
{
    return json_decode(json_encode([
        'site' => 'shop-api',
        'networks' => [
            ['name' => 'shop_default', 'aliases' => ['api'], 'compose' => ['project' => 'shop', 'network' => 'default']],
            ['name' => 'shared', 'aliases' => ['api']],
        ],
    ]));
}

it('keeps a compose network for agents that create them', function () {
    $out = PayloadCompatibility::adapt('deploy.container.swap', compat_swap(), ['docker.networks', 'docker.networks.create']);

    expect($out->networks[0]->compose->project)->toBe('shop');
});

it('strips the compose marker for agents that only join existing networks (they keep waiting for it)', function () {
    $out = PayloadCompatibility::adapt('deploy.container.swap', compat_swap(), ['docker.networks']);

    expect(property_exists($out->networks[0], 'compose'))->toBeFalse()
        ->and($out->networks[0]->name)->toBe('shop_default')
        ->and($out->networks[0]->aliases)->toBe(['api']);
});

it('strips the networks for agents that join none', function () {
    expect(property_exists(PayloadCompatibility::adapt('docker.run', compat_swap(), []), 'networks'))->toBeFalse();
});

it('strips machine-check decisions from provision.apply for agents without provision.v2', function () {
    $plan = fn () => json_decode(json_encode(['hostname' => 'app-1', 'components' => [['name' => 'docker', 'decision' => 'adopt', 'packages' => ['docker-ce']]]]));

    expect(property_exists(PayloadCompatibility::adapt('provision.apply', $plan(), ['compose.up.services']), 'components'))->toBeFalse()
        ->and(PayloadCompatibility::adapt('provision.apply', $plan(), ['provision.v2'])->components[0]->decision)->toBe('adopt')
        ->and(PayloadCompatibility::adapt('provision.apply', $plan(), [])->hostname)->toBe('app-1');
});
