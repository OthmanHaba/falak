<?php

namespace Kiln\Servers\Application\Actions;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Servers\Domain\Models\SshKey;

final class CreateSshKey
{
    public function __construct(private readonly AuditLog $audit) {}

    public function __invoke(string $organizationId, ?string $userId, string $name, string $publicKey): SshKey
    {
        try {
            $parsed = SshKey::parse($publicKey);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['public_key' => $e->getMessage()]);
        }

        if (SshKey::query()->where('organization_id', $organizationId)->where('fingerprint', $parsed['fingerprint'])->exists()) {
            throw ValidationException::withMessages(['public_key' => 'This key has already been added.']);
        }

        $key = SshKey::query()->create([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'name' => $name,
            'public_key' => $parsed['public_key'],
            'fingerprint' => $parsed['fingerprint'],
        ]);

        $this->audit->record('ssh_key.created', 'ssh_key', $key->id, ['name' => $name, 'fingerprint' => $key->fingerprint], $organizationId);

        return $key;
    }
}
