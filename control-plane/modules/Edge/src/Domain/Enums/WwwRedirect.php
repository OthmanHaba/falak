<?php

namespace Falak\Edge\Domain\Enums;

enum WwwRedirect: string
{
    case None = 'none';
    /** Serve www.<domain>; redirect the apex to it. */
    case ToWww = 'to_www';
    /** Serve the apex; redirect www.<domain> to it. */
    case ToApex = 'to_apex';
}
