<?php

namespace Falak\Identity\Http\Controllers\Organizations;

use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Domain\Models\Organization;

trait ResolvesOrganization
{
    protected function organization(): Organization
    {
        return Organization::query()->findOrFail(app(CurrentOrganization::class)->requireId());
    }
}
