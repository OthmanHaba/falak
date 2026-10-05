<?php

namespace Falak\Functions\Application\Listeners;

use Falak\Functions\Application\FunctionStore;
use Falak\Sites\Events\SiteDeleted;

final class ForgetDeletedFunction
{
    public function __construct(private readonly FunctionStore $functions) {}

    public function handle(SiteDeleted $event): void
    {
        $this->functions->forget($event->siteId);
    }
}
