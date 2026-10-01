<?php

namespace Kiln\Deployments\Contracts\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * Another deployment of the site was being started and did not finish in time (the site's trigger lock). Nothing
 * was queued: retry. A ValidationException with status 409, so forms show it and APIs answer 409.
 */
final class DeploymentTriggerBusy extends ValidationException
{
    public static function forSite(): self
    {
        /** @var self $exception */
        $exception = self::withMessages(['deployment' => 'Another deployment of this site is being started. Try again in a moment.']);

        return $exception->status(409);
    }
}
