<?php

namespace Kiln\Servers\Contracts;

/**
 * creating → provisioning → active; any → error; any → deleting.
 * "creating" covers machine creation at the provider and waiting for the agent to enroll.
 */
enum ServerStatus: string
{
    case Creating = 'creating';
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Error = 'error';
    case Deleting = 'deleting';
}
