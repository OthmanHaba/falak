<?php

namespace Kiln\Identity\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Identity\Domain\Models\Organization;
use Kiln\Identity\Domain\Models\User;
use Kiln\Identity\Events\OrganizationDeleted;

/**
 * Deletes a user account together with their personal organization. Refuses while the user
 * still owns a shared organization (ownership must be transferred or the organization deleted).
 */
final class DeleteAccount
{
    public function __construct(private readonly AuditLog $audit) {}

    public function __invoke(User $user): void
    {
        $owned = Organization::query()->where('owner_id', $user->id)->get();

        if ($owned->contains(fn (Organization $organization) => ! $organization->personal)) {
            throw ValidationException::withMessages([
                'password' => 'Transfer or delete the organizations you own before deleting your account.',
            ]);
        }

        $this->audit->recordPersonal('user.deleted', $user->id, ['email' => $user->email]);

        DB::transaction(function () use ($user, $owned) {
            foreach ($owned as $organization) {
                DB::table('identity_model_has_roles')->where('organization_id', $organization->id)->delete();
                $organization->delete();
            }

            DB::table('identity_model_has_roles')->where('model_id', $user->id)->delete();
            $user->tokens()->delete();
            $user->delete();
        });

        foreach ($owned as $organization) {
            OrganizationDeleted::dispatch($organization->id);
        }
    }
}
