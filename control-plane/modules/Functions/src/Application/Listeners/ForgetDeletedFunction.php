<?php

namespace Kiln\Functions\Application\Listeners;

use Kiln\Functions\Application\FunctionStore;
use Kiln\Sites\Events\SiteDeleted;

final class ForgetDeletedFunction
{
    public function __construct(private readonly FunctionStore $functions) {}

    public function handle(SiteDeleted $event): void
    {
        $this->functions->forget($event->siteId);
    }
}
