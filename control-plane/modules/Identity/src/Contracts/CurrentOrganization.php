<?php

namespace Kiln\Identity\Contracts;

use Kiln\Identity\Contracts\Data\OrganizationData;
use Kiln\Identity\Contracts\Exceptions\NoCurrentOrganization;

/**
 * The organization (tenant) the current request / job operates in.
 *
 * Resolution order for HTTP: API token's organization → session selection → the user's last
 * selected organization. Jobs and CLI commands set it explicitly with {@see run()}.
 */
interface CurrentOrganization
{
    public function id(): ?string;

    public function get(): ?OrganizationData;

    /**
     * @throws NoCurrentOrganization
     */
    public function require(): OrganizationData;

    /**
     * @throws NoCurrentOrganization
     */
    public function requireId(): string;

    /**
     * Run a callback with the given organization as current, restoring the previous one afterwards.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(string $organizationId, callable $callback): mixed;
}
