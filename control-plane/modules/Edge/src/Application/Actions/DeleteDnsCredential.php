<?php

namespace Kiln\Edge\Application\Actions;

use Illuminate\Validation\ValidationException;
use Kiln\Edge\Domain\Models\DnsCredential;
use Kiln\Edge\Domain\Models\Domain;
use Kiln\Identity\Contracts\AuditLog;

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
