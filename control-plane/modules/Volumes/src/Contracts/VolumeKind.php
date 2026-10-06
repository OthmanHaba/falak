<?php

namespace Falak\Volumes\Contracts;

/**
 * Where a volume's data lives on its server.
 */
enum VolumeKind: string
{
    /** A Docker named volume (compose stacks declare them). */
    case Docker = 'docker';

    /** An ext4 image file loop-mounted under /var/lib/falak/volumes/<id>: a hard size limit, grown online. */
    case Sized = 'sized';

    /** A host path from the agent's allowlist (admins only). */
    case Bind = 'bind';

    /** A classic site's shared path (/srv/falak/sites/<slug>/shared/<path>), on every server of the site. */
    case SharedPath = 'shared_path';

    public function label(): string
    {
        return match ($this) {
            self::Docker => 'Docker volume',
            self::Sized => 'Sized volume',
            self::Bind => 'Host path',
            self::SharedPath => 'Shared path',
        };
    }

    /** Containers mount it (docker sites, compose services, database containers). */
    public function mountable(): bool
    {
        return $this !== self::SharedPath;
    }

    /** Its data can be archived, restored, cloned and moved by the agent. */
    public function portable(): bool
    {
        return $this === self::Docker || $this === self::Sized;
    }
}
