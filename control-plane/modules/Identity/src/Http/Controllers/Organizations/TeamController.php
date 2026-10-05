<?php

namespace Falak\Identity\Http\Controllers\Organizations;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Falak\Identity\Application\Actions\CreateTeam;
use Falak\Identity\Application\Actions\DeleteTeam;
use Falak\Identity\Application\Actions\SyncTeamMembers;
use Falak\Identity\Application\Actions\UpdateTeam;
use Falak\Identity\Domain\Models\Team;
use Falak\Identity\Domain\Models\User;
use Falak\Kernel\Http\Controller;

final class TeamController extends Controller
{
    use ResolvesOrganization;

    public function index(Request $request): Response
    {
        $organization = $this->organization();
        $this->authorize('viewMembers', $organization);

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Identity/organizations/teams', [
            'teams' => $organization->teams()->with('members:identity_users.id,name,email')->orderBy('name')->get()->map(fn (Team $team) => [
                'id' => $team->id,
                'name' => $team->name,
                'description' => $team->description,
                'members' => $team->members->map(fn (User $member) => ['id' => $member->id, 'name' => $member->name, 'email' => $member->email])->values(),
            ]),
            'members' => $organization->members()->orderBy('name')->get(['identity_users.id', 'name', 'email'])
                ->map(fn (User $member) => ['id' => $member->id, 'name' => $member->name, 'email' => $member->email]),
            'canManage' => $user->can('manageTeams', $organization),
        ]);
    }

    public function store(Request $request, CreateTeam $create): RedirectResponse
    {
        $organization = $this->organization();
        $this->authorize('manageTeams', $organization);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('identity_teams')->where('organization_id', $organization->id)],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $create($organization, $data['name'], $data['description'] ?? null);

        return back();
    }

    public function update(Request $request, string $team, UpdateTeam $update): RedirectResponse
    {
        $organization = $this->organization();
        $this->authorize('manageTeams', $organization);
        $model = $organization->teams()->findOrFail($team);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('identity_teams')->where('organization_id', $organization->id)->ignore($model->id)],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $update($model, $data['name'], $data['description'] ?? null);

        return back();
    }

    public function members(Request $request, string $team, SyncTeamMembers $sync): RedirectResponse
    {
        $organization = $this->organization();
        $this->authorize('manageTeams', $organization);

        $data = $request->validate(['user_ids' => ['present', 'array'], 'user_ids.*' => ['string']]);

        $sync($organization->teams()->findOrFail($team), array_values($data['user_ids']));

        return back();
    }

    public function destroy(string $team, DeleteTeam $delete): RedirectResponse
    {
        $organization = $this->organization();
        $this->authorize('manageTeams', $organization);

        $delete($organization->teams()->findOrFail($team));

        return back();
    }
}
