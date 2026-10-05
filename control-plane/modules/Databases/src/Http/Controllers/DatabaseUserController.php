<?php

namespace Falak\Databases\Http\Controllers;

use Falak\Databases\Application\Actions\CreateDatabaseUser;
use Falak\Databases\Application\Actions\DeleteDatabaseUser;
use Falak\Databases\Application\Actions\RevealDatabaseUserPassword;
use Falak\Databases\Application\Actions\RotateDatabaseUserPassword;
use Falak\Databases\Application\Actions\UpdateDatabaseUser;
use Falak\Databases\Domain\Models\DatabaseServer;
use Falak\Databases\Domain\Models\DatabaseUser;
use Falak\Kernel\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class DatabaseUserController extends Controller
{
    /**
     * @return array<string, list<string>>
     */
    private function grantRules(): array
    {
        return [
            'grants' => ['present', 'array', 'max:200'],
            'grants.*.database_id' => ['required', 'string'],
            'grants.*.privileges' => ['nullable', 'array', 'max:30'],
            'grants.*.privileges.*' => ['string', 'regex:/^[A-Z ]+$/'],
        ];
    }

    public function store(Request $request, DatabaseServer $databaseServer, CreateDatabaseUser $create): RedirectResponse
    {
        $this->authorize('manage', $databaseServer);

        $data = $request->validate([
            'username' => ['required', 'string', 'max:63'],
            'password' => ['nullable', 'string', 'min:12', 'max:128'],
            'host' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.%_:-]+$/'],
            'site_id' => ['nullable', 'ulid'],
            ...$this->grantRules(),
        ]);

        $create($databaseServer, $data, $request->user()?->getAuthIdentifier());

        return back();
    }

    public function update(Request $request, DatabaseUser $databaseUser, UpdateDatabaseUser $update): RedirectResponse
    {
        $this->authorize('manage', $databaseUser);

        $data = $request->validate([
            'host' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.%_:-]+$/'],
            ...$this->grantRules(),
        ]);

        $update($databaseUser, $data);

        return back();
    }

    public function password(Request $request, DatabaseUser $databaseUser, RotateDatabaseUserPassword $rotate): RedirectResponse
    {
        $this->authorize('manage', $databaseUser);

        $data = $request->validate(['password' => ['nullable', 'string', 'min:12', 'max:128']]);

        $rotate($databaseUser, $data['password'] ?? null);

        return back();
    }

    public function reveal(DatabaseUser $databaseUser, RevealDatabaseUserPassword $reveal): JsonResponse
    {
        $this->authorize('reveal', $databaseUser);

        return response()->json(['password' => $reveal($databaseUser)])->header('Cache-Control', 'no-store');
    }

    public function destroy(DatabaseUser $databaseUser, DeleteDatabaseUser $delete): RedirectResponse
    {
        $this->authorize('manage', $databaseUser);

        $delete($databaseUser);

        return back();
    }
}
