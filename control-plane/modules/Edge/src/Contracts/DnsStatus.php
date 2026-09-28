<?php

namespace Kiln\Edge\Contracts;

enum DnsStatus: string
{
    /** Every address points at the site's servers (or load balancer). */
    case Ok = 'ok';
    /** Resolves, but (also) to other addresses. */
    case Mismatch = 'mismatch';
    /** Resolves to Cloudflare's proxy (orange cloud): Let's Encrypt HTTP-01 may fail. */
    case Proxied = 'proxied';
    /** No A/AAAA record (yet). */
    case Missing = 'missing';
    /** The lookup failed, or there is nothing to compare with (no server IP yet). */
    case Error = 'error';
}
