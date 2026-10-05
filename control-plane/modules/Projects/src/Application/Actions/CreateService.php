<?php

namespace Falak\Projects\Application\Actions;

use Illuminate\Validation\ValidationException;
use Falak\Databases\Contracts\DatabaseProvisioner;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Projects\Domain\Models\Environment;
use Falak\Projects\Domain\Models\Service;
use Falak\Sites\Contracts\Data\SitePlacement;
use Falak\Sites\Contracts\SiteFactory;

/**
 * The canvas Create picker: create a site (through Sites' SiteFactory) or a database (through
 * Databases' DatabaseProvisioner) and place it in the environment at the given position.
 */
final class CreateService
{
    /** @var list<string> non-fatal warnings from the owning module (e.g. deploy key installation) */
    public array $warnings = [];

    public function __construct(
        private readonly SiteFactory $sites,
        private readonly DatabaseProvisioner $databases,
        private readonly LinkService $link,
    ) {}

    /**
     * @param  array<string, mixed>  $data  {kind: site, …site fields} | {kind: database, engine, server_id, name}; optional x, y
     *
     * @throws ValidationException
     */
    public function __invoke(Environment $environment, ?string $userId, ServiceKind $kind, array $data, ?int $x = null, ?int $y = null): Service
    {
        $this->warnings = [];

        if ($kind === ServiceKind::Site) {
            $created = $this->sites->create(
                $environment->organization_id,
                $userId,
                $data,
                new SitePlacement($environment->project_id, $environment->id, $x, $y),
            );
            $this->warnings = $created->warnings;

            // The SiteCreated listener already placed it; linking is idempotent.
            return ($this->link)($environment, ServiceKind::Site, $created->site->id, $created->site->name, $x, $y);
        }

        $database = $this->databases->create(
            $environment->organization_id,
            (string) $data['server_id'],
            (string) $data['engine'],
            (string) $data['name'],
            $userId,
            array_intersect_key($data, array_flip(['maxmemory_mb', 'eviction', 'persistence'])),
        );

        return ($this->link)($environment, ServiceKind::Database, $database->id, $database->name, $x, $y);
    }
}
