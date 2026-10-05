<?php

use Falak\Deployments\Application\Listeners\DeploySplitSitesFirst;

// A queued listener's failed() is Laravel's hook for a failed job (CallQueuedListener calls it with the event and the
// exception): the listener's handlers must not use that name, and lock contention is retried.
it('keeps clear of the queued-listener failure hook and retries a busy trigger', function () {
    $listener = new ReflectionClass(DeploySplitSitesFirst::class);

    expect($listener->hasMethod('failed'))->toBeFalse()
        ->and($listener->hasMethod('onStackFailed'))->toBeTrue()
        ->and($listener->hasMethod('onSucceeded'))->toBeTrue()
        ->and($listener->getProperty('tries')->getDefaultValue())->toBe(3)
        ->and($listener->getProperty('backoff')->getDefaultValue())->toBe([10, 30]);
});
