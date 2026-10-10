<?php

namespace Falak\Volumes\Contracts;

use Falak\Sites\Contracts\Data\SharedPath;
use Falak\Volumes\Contracts\Data\Mount;

/**
 * What a service mounts, for the deploy payloads (Deployments).
 */
interface VolumeMounts
{
    /**
     * A docker site's volumes on one server (deploy.container.swap `volumes`): its active attachments to volumes of
     * that server.
     *
     * @return list<Mount>
     */
    public function forSite(string $siteId, string $serverId): array;

    /**
     * A classic site's shared paths (deploy.prepare `shared_paths`): its shared_path volumes, by path.
     *
     * @return list<SharedPath>
     */
    public function sharedPaths(string $siteId): array;
}
