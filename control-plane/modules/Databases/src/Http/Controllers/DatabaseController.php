<?php

namespace Kiln\Databases\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Kiln\Databases\Application\Actions\CreateDatabase;
use Kiln\Databases\Application\Actions\DeleteDatabase;
use Kiln\Databases\Application\Actions\RunBackup;
use Kiln\Databases\Domain\Enums\Compression;
use Kiln\Databases\Domain\Models\Database;
use Kiln\Databases\Domain\Models\DatabaseServer;
use Kiln\Databases\Domain\Models\StorageProvider;
use Kiln\Kernel\Http\Controller;

final class DatabaseController extends Controller
{
    public function store(Request $request, DatabaseServer $databaseServer, CreateDatabase $create): RedirectResponse
    {
        $this->authorize('manage', $databaseServer);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:63'],
            'charset' => ['nullable', 'string', 'max:32', 'regex:/^[a-z0-9_]+$/'],
            'collation' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'site_id' => ['nullable', 'ulid'],
            'user' => ['nullable', 'array'],
            'user.username' => ['nullable', 'string', 'max:63'],
            'user.password' => ['nullable', 'string', 'min:12', 'max:128'],
            'user.host' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.%_:-]+$/'],
        ]);

        $create($databaseServer, $data, $request->user()?->getAuthIdentifier());

        return back();
    }

    public function destroy(Request $request, Database $database, DeleteDatabase $delete): RedirectResponse
    {
        $this->authorize('manage', $database);

        $request->validate(['confirm' => ['required', 'string', 'in:'.$database->name]], ['confirm.in' => 'Type the database name to confirm.']);

        $delete($database);

        return back();
    }

    public function backup(Request $request, Database $database, RunBackup $run): RedirectResponse
    {
        $this->authorize('manage', $database);

        $data = $request->validate([
            'storage_provider_id' => ['required', 'string'],
            'compression' => ['nullable', 'in:gzip,none'],
        ]);

        $provider = StorageProvider::query()->where('organization_id', $database->organization_id)->findOrFail($data['storage_provider_id']);

        $run($database, $provider, Compression::from($data['compression'] ?? 'gzip'), 'manual', null, $request->user()?->getAuthIdentifier());

        return back();
    }
}
