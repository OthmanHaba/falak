<?php

namespace Kiln\Fleet\Application\Actions;

use DateTimeImmutable;
use Illuminate\Support\Str;
use Kiln\Fleet\Contracts\Data\InstallToken as InstallTokenData;
use Kiln\Fleet\Domain\Models\InstallToken;
use Kiln\Fleet\Infrastructure\PanelUrls;
use Kiln\Identity\Contracts\AuditLog;

final class IssueInstallToken
{
    public function __construct(
        private readonly PanelUrls $urls,
        private readonly AuditLog $audit,
        private readonly int $defaultTtlMinutes,
    ) {}

    public function __invoke(string $organizationId, ?string $serverId, ?int $ttlMinutes = null, ?string $createdBy = null): InstallTokenData
    {
        // A server has at most one usable token: issuing a new one invalidates the previous.
        if ($serverId !== null) {
            InstallToken::query()->where('server_id', $serverId)->usable()->update(['expires_at' => now()]);
        }

        $token = Str::random(48);
        $expiresAt = now()->addMinutes($ttlMinutes ?? $this->defaultTtlMinutes);

        $model = InstallToken::query()->create([
            'organization_id' => $organizationId,
            'server_id' => $serverId,
            'token_hash' => InstallToken::hash($token),
            'expires_at' => $expiresAt,
            'created_by' => $createdBy ?? auth()->id(),
        ]);

        $url = $this->urls->installScript($token);

        $this->audit->record('agent.install_token_issued', 'server', $serverId, ['expires_at' => $expiresAt->toIso8601String()], $organizationId);

        return new InstallTokenData(
            id: $model->id,
            token: $token,
            url: $url,
            command: "curl -fsSL {$url} | sudo sh",
            expiresAt: DateTimeImmutable::createFromMutable($expiresAt->toDateTime()),
        );
    }
}
