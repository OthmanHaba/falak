<?php

use Kiln\Databases\Application\ContainerNetworks;

it('keeps canonical IPv4 networks and clears host bits, as the agent expects', function () {
    expect(ContainerNetworks::parse('172.16.0.0/12, 192.168.0.0/16'))->toBe(['networks' => ['172.16.0.0/12', '192.168.0.0/16'], 'invalid' => []])
        ->and(ContainerNetworks::parse('172.16.0.1/12,10.10.3.7/16,172.16.0.0/12'))->toBe(['networks' => ['172.16.0.0/12', '10.10.0.0/16'], 'invalid' => []]);
});

it('drops entries that are not usable IPv4 ranges and falls back to the Docker defaults when none is left', function () {
    expect(ContainerNetworks::parse('10.20.0.0/16,fd00::/8,172.16.0.0,10.0.0.0/33,0.0.0.0/0,10.0.0.0/4,127.0.0.0/8,10.0.0.0/31,999.1.1.1/16,banana'))
        ->toBe(['networks' => ['10.20.0.0/16'], 'invalid' => ['fd00::/8', '172.16.0.0', '10.0.0.0/33', '0.0.0.0/0', '10.0.0.0/4', '127.0.0.0/8', '10.0.0.0/31', '999.1.1.1/16', 'banana']])
        ->and(ContainerNetworks::parse('172.16.0.0/12;192.168.0.0/16'))->toBe(['networks' => ContainerNetworks::DEFAULT, 'invalid' => ['172.16.0.0/12;192.168.0.0/16']]);
});

it('turns container access off for an empty value', function () {
    expect(ContainerNetworks::parse(''))->toBe(['networks' => [], 'invalid' => []])
        ->and(ContainerNetworks::parse(' , '))->toBe(['networks' => [], 'invalid' => []])
        ->and(ContainerNetworks::parse(null))->toBe(['networks' => [], 'invalid' => []]);
});
