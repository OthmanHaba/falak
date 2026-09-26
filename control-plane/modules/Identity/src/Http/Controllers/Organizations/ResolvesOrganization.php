<?php

namespace Kiln\Identity\Http\Controllers\Organizations;

use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Domain\Models\Organization;

trait ResolvesOrganization
{
    protected function organization(): Organization
    {
        return Organization::query()->findOrFail(app(CurrentOrganization::class)->requireId());
    }
}
