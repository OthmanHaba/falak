<?php

namespace Kiln\Projects\Application\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Kiln\Projects\Contracts\ServiceKind;
use Kiln\Projects\Domain\Models\Environment;
use Kiln\Projects\Domain\Models\Service;
use Kiln\Projects\Events\ServiceLinked;
use RuntimeException;

/**
 * Place a site / database in an environment. Idempotent: a service that is already placed (in any
 * environment) is returned unchanged.
 */
final class LinkService
{
    /** Auto-layout grid for services placed without coordinates. */
    public const COLUMNS = 4;

    public const CELL_WIDTH = 300;

    public const CELL_HEIGHT = 160;

    public function __invoke(Environment $environment, ServiceKind $kind, string $refId, string $name, ?int $x = null, ?int $y = null): Service
    {
        $refId = strtolower($refId);

        if ($existing = $this->existing($kind, $refId)) {
            return $existing;
        }

        [$x, $y] = $x !== null && $y !== null ? [$x, $y] : $this->nextFreeCell($environment);

        try {
            $service = Service::query()->create([
                'organization_id' => $environment->organization_id,
                'project_id' => $environment->project_id,
                'environment_id' => $environment->id,
                'kind' => $kind,
                'ref_id' => $refId,
                'name' => $this->uniqueName($environment, $name),
                'x' => $x,
                'y' => $y,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->existing($kind, $refId) ?? throw new RuntimeException('Service could not be linked.');
        }

        ServiceLinked::dispatch($service->id, $service->organization_id, $service->project_id, $service->environment_id, $kind->value, $refId);

        return $service;
    }

    private function existing(ServiceKind $kind, string $refId): ?Service
    {
        return Service::query()->where('kind', $kind)->where('ref_id', $refId)->first();
    }

    /**
     * Service names are unique per environment (compared by handle, so "Shop" and "shop" collide).
     */
    private function uniqueName(Environment $environment, string $name): string
    {
        $base = Str::limit(trim($name) ?: 'service', 60, '');
        $taken = Service::query()->where('environment_id', $environment->id)->pluck('name')->map(fn (string $n) => Service::handle($n))->all();
        $candidate = $base;

        for ($i = 2; in_array(Service::handle($candidate), $taken, true); $i++) {
            $candidate = "{$base}-{$i}";
        }

        return $candidate;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function nextFreeCell(Environment $environment): array
    {
        $occupied = Service::query()->where('environment_id', $environment->id)->get(['x', 'y'])
            ->map(fn (Service $s) => intdiv($s->x, self::CELL_WIDTH).':'.intdiv($s->y, self::CELL_HEIGHT))
            ->all();

        for ($cell = 0; ; $cell++) {
            $column = $cell % self::COLUMNS;
            $row = intdiv($cell, self::COLUMNS);

            if (! in_array("{$column}:{$row}", $occupied, true)) {
                return [$column * self::CELL_WIDTH, $row * self::CELL_HEIGHT];
            }
        }
    }
}
