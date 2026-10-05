<?php

namespace Falak\Edge\Application\Actions;

use Falak\Edge\Domain\Models\DnsCredential;
use Falak\Edge\Domain\Models\Domain;
use Falak\Identity\Contracts\AuditLog;
use Illuminate\Validation\ValidationException;

final class DeleteDnsCredential
{
    public function __construct(private readonly AuditLog $audit) {}

    public function __invoke(DnsCredential $credential): void
    {
        $inUse = Domain::query()->where('dns_credential_id', $credential->id)->pluck('name')->all();

        if ($inUse !== []) {
            throw ValidationException::withMessages(['credential' => 'The credential is used by '.implode(', ', $inUse).'.']);
        }

        $credential->delete();

        $this->audit->record('edge.dns_credential_deleted', 'dns_credential', $credential->id, ['name' => $credential->name], $credential->organization_id);
    }
}
