<?php

namespace Falak\Volumes\Domain\Enums;

enum VolumeStatus: string
{
    /** Being created on its server (volume.create, a restore or a clone into it). */
    case Pending = 'pending';
    case Active = 'active';
    case Failed = 'failed';
    /** volume.delete is on its way; the row goes when it succeeds. */
    case Deleting = 'deleting';
}
