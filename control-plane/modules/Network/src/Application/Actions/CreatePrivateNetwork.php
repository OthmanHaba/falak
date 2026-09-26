<?php

namespace Kiln\Network\Application\Actions;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Network\Domain\Models\PrivateNetwork;
use Kiln\Network\Domain\Support\Ipv4Cidr;

final class CreatePrivateNetwork
{
    public const MIN_PREFIX = 16;

    public const MAX_PREFIX = 29;

    public function __construct(private readonly AuditLog $audit) {}

    /**
     * @param  array{name: string, cidr?: ?string, listen_port?: ?int}  $data  validated
     */
    public function __invoke(string $organizationId, array $data): PrivateNetwork
    {
        $cidr = ($data['cidr'] ?? null) ?: (string) config('network.private_cidr', '10.90.0.0/24');

        try {
            $range = Ipv4Cidr::parse($cidr);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['cidr' => 'Enter an IPv4 CIDR such as 10.90.0.0/24.']);
        }

        if ($range->prefix < self::MIN_PREFIX || $range->prefix > self::MAX_PREFIX) {
            throw ValidationException::withMessages(['cidr' => 'The prefix length must be between /'.self::MIN_PREFIX.' and /'.self::MAX_PREFIX.'.']);
        }

        $overlapping = PrivateNetwork::query()
            ->where('organization_id', $organizationId)
            ->get()
            ->first(fn (PrivateNetwork $existing) => $existing->range()->overlaps($range));

        if ($overlapping) {
            throw ValidationException::withMessages(['cidr' => "The range overlaps the {$overlapping->name} network ({$overlapping->cidr})."]);
        }

        $network = PrivateNetwork::query()->create([
            'organization_id' => $organizationId,
            'name' => $data['name'],
            'cidr' => (string) $range,
            'interface' => $this->interfaceName(),
            'listen_port' => (int) (($data['listen_port'] ?? null) ?: config('network.wireguard_port', 51820)),
        ]);

        $this->audit->record('network.private_network_created', 'private_network', $network->id, [
            'name' => $network->name,
            'cidr' => $network->cidr,
            'listen_port' => $network->listen_port,
        ], $organizationId);

        return $network;
    }

    private function interfaceName(): string
    {
        do {
            $name = 'wg-'.Str::lower(Str::random(8));
        } while (PrivateNetwork::query()->where('interface', $name)->exists() || ! preg_match('/^[a-z0-9-]{1,15}$/', $name));

        return $name;
    }
}
