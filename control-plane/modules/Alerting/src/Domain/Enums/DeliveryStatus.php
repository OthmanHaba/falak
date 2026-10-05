<?php

namespace Falak\Alerting\Domain\Enums;

enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    /** The last attempt failed (retries may still follow). */
    case Failed = 'failed';
}
