<?php

namespace Falak\Processes\Domain\Enums;

/**
 * Octane of a site on one server, as far as routing is concerned.
 */
enum OctaneRouteStatus: string
{
    /** Program (re)configured; waiting for the probe to find it listening. The edge serves the site directly. */
    case Starting = 'starting';
    /** Verified answering HTTP on 127.0.0.1:<port>: the edge reverse-proxies to it. */
    case Listening = 'listening';
    /** The probe gave up (never deployed yet, crash, missing extension …). Re-probed after restarts and by the status poll. */
    case Failed = 'failed';
    /** Octane switched off: the edge is switching back first; the program stops once that config is applied. */
    case Draining = 'draining';
}
