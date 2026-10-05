<?php

namespace Falak\Templates\Tests\Support;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Falak\Deployments\Contracts\DeploymentTrigger;

final class FakeDeploymentTrigger implements DeploymentTrigger
{
    /** @var list<array{site_id: string, requested_by: ?string, id: string}> */
    public array $deployed = [];

    public bool $fail = false;

    public function deploy(string $siteId, ?string $requestedBy = null, ?string $commit = null, ?string $message = null, ?string $author = null): string
    {
        if ($this->fail) {
            throw ValidationException::withMessages(['site' => 'Connect a repository before deploying.']);
        }

        $id = strtolower((string) Str::ulid());
        $this->deployed[] = ['site_id' => $siteId, 'requested_by' => $requestedBy, 'id' => $id];

        return $id;
    }
}
