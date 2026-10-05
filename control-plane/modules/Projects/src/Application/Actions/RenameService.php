<?php

namespace Falak\Projects\Application\Actions;

use Illuminate\Validation\ValidationException;
use Falak\Projects\Domain\Models\Service;

/**
 * Rename a canvas service (its display name and the handle `${{ name.KEY }}` references use). The site /
 * database keeps its own name. Names stay unique per environment by handle.
 */
final class RenameService
{
    /**
     * @throws ValidationException
     */
    public function __invoke(Service $service, string $name): Service
    {
        $name = trim($name);
        $handle = Service::handle($name);

        if ($handle === '') {
            throw ValidationException::withMessages(['name' => 'Use at least one letter or number.']);
        }

        $taken = Service::query()
            ->where('environment_id', $service->environment_id)
            ->whereKeyNot($service->id)
            ->pluck('name')
            ->contains(fn (string $other) => Service::handle($other) === $handle);

        if ($taken) {
            throw ValidationException::withMessages(['name' => 'Another service in this environment already uses that name.']);
        }

        $service->forceFill(['name' => $name])->save();

        return $service;
    }
}
