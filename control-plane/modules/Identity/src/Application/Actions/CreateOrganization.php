<?php

namespace Falak\Identity\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\Organization;
use Falak\Identity\Domain\Models\User;
use Falak\Identity\Events\OrganizationCreated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateOrganization
{
    public function __construct(
        private readonly AssignRole $assignRole,
        private readonly SwitchOrganization $switch,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(User $owner, string $name, bool $personal = false): Organization
    {
        $organization = DB::transaction(function () use ($owner, $name, $personal) {
            $organization = Organization::query()->create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'owner_id' => $owner->id,
                'personal' => $personal,
            ]);

            $organization->members()->attach($owner->id);
            ($this->assignRole)($owner, $organization->id, Role::Owner);

            return $organization;
        });

        ($this->switch)($owner, $organization->id);

        $this->audit->record('organization.created', 'organization', $organization->id, ['name' => $name], $organization->id, $owner->id);
        OrganizationCreated::dispatch($organization->id, $owner->id);

        return $organization;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'org';
        $slug = $base;

        while (Organization::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }
}
