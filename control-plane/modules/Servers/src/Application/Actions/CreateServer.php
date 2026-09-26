<?php

namespace Kiln\Servers\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Kiln\Fleet\Contracts\Enrollment;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Providers\Contracts\ProviderGateway;
use Kiln\Providers\Contracts\ProviderType;
use Kiln\Servers\Application\Jobs\CreateProviderMachine;
use Kiln\Servers\Contracts\ServerStatus;
use Kiln\Servers\Contracts\ServerType;
use Kiln\Servers\Domain\Enums\PhpVersionStatus;
use Kiln\Servers\Domain\Models\PhpVersion;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Servers\Domain\Models\SshKey;
use Kiln\Servers\Domain\Stack\Stack;
use Kiln\Servers\Events\ServerCreated;

/**
 * Registers a server. Provider servers are created asynchronously at the provider with a cloud-init
 * that runs the agent installer; custom servers show the install command to run by hand.
 */
final class CreateServer
{
    public function __construct(
        private readonly ProviderGateway $providers,
        private readonly Enrollment $enrollment,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array{name: string, type: string, provider: string, credential_id?: ?string, region?: ?string, size?: ?string, image?: ?string, stack?: array<string, mixed>|null, ssh_key_ids?: list<string>, timezone?: ?string}  $input
     */
    public function __invoke(string $organizationId, ?string $userId, array $input): Server
    {
        $type = ServerType::from($input['type']);
        $provider = ProviderType::from($input['provider']);
        $stack = isset($input['stack']) ? Stack::fromArray($input['stack']) : Stack::defaultsFor($type);

        if (($errors = $stack->errorsFor($type)) !== []) {
            throw ValidationException::withMessages($errors);
        }

        $credentialId = null;

        if ($provider->hasApi()) {
            $credential = $this->providers->credential($organizationId, (string) ($input['credential_id'] ?? ''));

            if (! $credential || $credential->provider !== $provider) {
                throw ValidationException::withMessages(['credential_id' => 'Choose a credential for this provider.']);
            }

            foreach (['region', 'size', 'image'] as $field) {
                if (empty($input[$field])) {
                    throw ValidationException::withMessages([$field => "The {$field} is required for provider servers."]);
                }
            }

            $credentialId = $credential->id;
        }

        $sshKeyIds = array_values(array_unique($input['ssh_key_ids'] ?? []));

        if (SshKey::query()->where('organization_id', $organizationId)->whereIn('id', $sshKeyIds)->count() !== count($sshKeyIds)) {
            throw ValidationException::withMessages(['ssh_key_ids' => 'Unknown SSH key.']);
        }

        $server = DB::transaction(function () use ($organizationId, $userId, $input, $type, $provider, $stack, $credentialId, $sshKeyIds) {
            $server = Server::query()->create([
                'organization_id' => $organizationId,
                'name' => $input['name'],
                'type' => $type,
                'status' => ServerStatus::Creating,
                'provider' => $provider->value,
                'provider_credential_id' => $credentialId,
                'region' => $provider->hasApi() ? $input['region'] : null,
                'size' => $provider->hasApi() ? $input['size'] : null,
                'image' => $provider->hasApi() ? $input['image'] : null,
                'timezone' => $input['timezone'] ?? 'UTC',
                'stack' => $stack,
                'created_by' => $userId,
            ]);

            foreach ($stack->phpVersions as $version) {
                $server->phpVersions()->create([
                    'version' => $version,
                    'status' => PhpVersionStatus::Installing,
                    'is_default' => $version === $stack->phpDefault,
                    'ini' => PhpVersion::DEFAULT_INI,
                    'fpm' => PhpVersion::defaultFpm(null),
                ]);
            }

            $server->sshKeys()->attach(array_fill_keys($sshKeyIds, ['unix_user' => (string) config('servers.unix_user', 'kiln')]));

            $install = $this->enrollment->issueInstallToken($organizationId, $server->id, $provider->hasApi() ? 60 * 24 : 60 * 24 * 7);
            $server->forceFill(['install_command' => $install->command])->save();

            return $server;
        });

        $this->audit->record('server.created', 'server', $server->id, [
            'name' => $server->name,
            'type' => $type->value,
            'provider' => $provider->value,
            'region' => $server->region,
            'size' => $server->size,
        ], $organizationId, $userId);

        ServerCreated::dispatch($server->id, $organizationId, $type->value, $server->name);

        if ($provider->hasApi()) {
            CreateProviderMachine::dispatch($server->id);
        }

        return $server;
    }
}
