<?php

namespace Falak\SourceControl\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\SourceControl\Contracts\Exceptions\SourceControlException;
use Falak\SourceControl\Contracts\ProviderType;
use Falak\SourceControl\Domain\Models\Connection;
use Falak\SourceControl\Infrastructure\Providers\ProviderClients;
use Illuminate\Validation\ValidationException;

/**
 * Store a connection after verifying its credentials against the provider API.
 */
final class CreateConnection
{
    public function __construct(
        private readonly ProviderClients $clients,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $credentials
     *
     * @throws ValidationException when the provider rejects the credentials
     */
    public function __invoke(
        string $organizationId,
        ?string $userId,
        ProviderType $provider,
        string $authType,
        array $credentials,
        ?string $name = null,
        ?string $baseUrl = null,
        ?string $account = null,
    ): Connection {
        $connection = new Connection([
            'organization_id' => $organizationId,
            'provider' => $provider,
            'auth_type' => $authType,
            'base_url' => $baseUrl ? rtrim($baseUrl, '/') : null,
            'account' => $account,
            'created_by' => $userId,
        ]);
        $connection->credentials = $credentials;

        if ($provider->hasApi() && $account === null) {
            try {
                $account = $this->clients->for($provider)->account($connection) ?: null;
            } catch (SourceControlException $e) {
                throw ValidationException::withMessages(['credentials' => $e->getMessage()]);
            }
        }

        // The immutable id previews match pull request commenters against (approvals): never a login.
        if ($provider->hasApi() && $authType !== 'app') {
            try {
                $connection->account_id = $this->clients->for($provider)->accountId($connection);
            } catch (SourceControlException) {
                $connection->account_id = null;
            }
        }

        $connection->account = $account;
        $connection->name = $this->uniqueName($organizationId, $name ?: $provider->label().($account ? " ({$account})" : ''));
        $connection->save();

        $this->audit->record('source_control.connected', 'source_control_connection', $connection->id, [
            'provider' => $provider->value,
            'auth_type' => $authType,
            'account' => $account,
            'base_url' => $connection->base_url,
        ], $organizationId);

        return $connection;
    }

    private function uniqueName(string $organizationId, string $name): string
    {
        $candidate = mb_substr($name, 0, 200);

        for ($i = 2; Connection::query()->where('organization_id', $organizationId)->where('name', $candidate)->exists(); $i++) {
            $candidate = mb_substr($name, 0, 200)." {$i}";
        }

        return $candidate;
    }
}
