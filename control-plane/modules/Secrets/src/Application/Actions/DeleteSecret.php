<?php

namespace Falak\Secrets\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Secrets\Domain\Models\Secret;
use Illuminate\Support\Facades\DB;

/**
 * Delete a secret and every version. Its access log stays (until pruned); references to it fail the next deploy.
 */
final class DeleteSecret
{
    public function __construct(private readonly AuditLog $audit) {}

    public function __invoke(Secret $secret): void
    {
        DB::transaction(function () use ($secret) {
            $secret->versions()->delete();
            $secret->delete();

            $this->audit->record('secret.deleted', 'secret', $secret->id, ['name' => $secret->name, 'scope' => $secret->scope_type->value, 'scope_id' => $secret->scope_id], $secret->organization_id);
        });
    }
}
