<?php

namespace Kiln\Identity\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Identity\Domain\Models\Organization;
use Kiln\Identity\Domain\Models\PersonalAccessToken;
use Kiln\Identity\Domain\Models\User;
use Kiln\Identity\Events\OrganizationDeleted;

/**
 * Deletes an organization. Other modules clean up their tenant data by listening to OrganizationDeleted.
 */
final class DeleteOrganization
{
    public function __construct(private readonly AuditLog $audit) {}

    public function __invoke(Organization $organization, User $actor): void
    {
        if ($organization->personal) {
            throw ValidationException::withMessages(['organization' => 'Personal organizations cannot be deleted.']);
        }

        $id = $organization->id;
        $memberIds = $organization->members()->pluck('identity_users.id')->all();

        DB::transaction(function () use ($organization, $id) {
            DB::table('identity_model_has_roles')->where('organization_id', $id)->delete();
            DB::table('identity_model_has_permissions')->where('organization_id', $id)->delete();
            PersonalAccessToken::query()->where('organization_id', $id)->delete();
            User::query()->where('current_organization_id', $id)->update(['current_organization_id' => null]);
            $organization->delete();
        });

        $this->audit->record('organization.deleted', 'organization', $id, ['name' => $organization->name, 'members' => count($memberIds)], $id, $actor->id);
        OrganizationDeleted::dispatch($id);
    }
}
