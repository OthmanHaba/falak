<?php

namespace Falak\Deployments\Contracts;

/**
 * Images releases may still run: the image of a Docker site's release and every `image:` of a rendered compose
 * release, for releases that are pending, live or kept as rollback targets (not failed or pruned). The registry
 * cleanup never deletes these.
 */
interface RetainedImages
{
    /**
     * @return list<string> image references as deployed (`host/repo:tag`, `host/repo@sha256:…`, `host/repo:tag@sha256:…`)
     */
    public function images(): array;
}
