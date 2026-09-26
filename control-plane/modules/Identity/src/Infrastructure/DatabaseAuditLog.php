<?php

namespace Kiln\Identity\Infrastructure;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Domain\Models\AuditEntry;
use Kiln\Identity\Domain\Models\PersonalAccessToken;
use Kiln\Identity\Domain\Models\User;

final class DatabaseAuditLog implements AuditLog
{
    /** Context keys whose values are never persisted. */
    private const SENSITIVE = ['password', 'secret', 'token', 'api_key', 'apikey', 'authorization', 'cookie', 'private_key', 'credentials'];

    /** Keys added by the logger itself that merely name something (never secret material). */
    private const SAFE = ['via_token'];

    public function __construct(
        private readonly Container $container,
        private readonly AuthFactory $auth,
        private readonly CurrentOrganization $organization,
    ) {}

    public function record(
        string $action,
        ?string $subjectType = null,
        ?string $subjectId = null,
        array $context = [],
        ?string $organizationId = null,
        ?string $actorId = null,
    ): void {
        $this->write($action, $subjectType, $subjectId, $context, $organizationId ?? $this->organization->id(), $actorId);
    }

    public function recordPersonal(string $action, string $userId, array $context = []): void
    {
        $this->write($action, 'user', $userId, $context, null, $userId);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function write(string $action, ?string $subjectType, ?string $subjectId, array $context, ?string $organizationId, ?string $actorId): void
    {
        [$actorType, $resolvedActorId, $actorName, $extra] = $this->actor($actorId);

        $request = $this->container->bound('request') ? $this->container->make('request') : null;
        $http = $request instanceof Request && ! $this->container->runningInConsole();

        AuditEntry::query()->create([
            'organization_id' => $organizationId,
            'actor_type' => $actorType,
            'actor_id' => $resolvedActorId,
            'actor_name' => $actorName,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'context' => $this->redact([...$context, ...$extra]),
            'ip_address' => $http ? $request->ip() : null,
            'user_agent' => $http ? Str::limit((string) $request->userAgent(), 500, '') : null,
        ]);
    }

    /**
     * @return array{0: string, 1: ?string, 2: ?string, 3: array<string, mixed>}
     */
    private function actor(?string $actorId): array
    {
        if ($actorId !== null) {
            $user = User::query()->find($actorId);

            return ['user', $actorId, $user?->name, []];
        }

        $user = $this->auth->guard()->user();

        if (! $user instanceof User) {
            return ['system', null, 'System', []];
        }

        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            return ['token', $user->id, $user->name, ['via_token' => $token->name]];
        }

        return ['user', $user->id, $user->name, []];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_string($key) && ! in_array(Str::lower($key), self::SAFE, true) && Str::contains(Str::lower($key), self::SENSITIVE)) {
                $context[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $context[$key] = $this->redact($value);
            }
        }

        return $context;
    }
}
