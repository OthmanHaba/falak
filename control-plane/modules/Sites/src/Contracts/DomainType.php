<?php

namespace Falak\Sites\Contracts;

/**
 * How a site (or a compose public service) gets its public name when it is created.
 */
enum DomainType: string
{
    /** <label>.<ip-with-dashes>.<suffix> (sslip.io by default): resolves to the server's IP with no DNS setup. */
    case Generated = 'generated';
    /** <slug>.<FALAK_TEST_DOMAIN> (wildcard DNS run by the operator). */
    case Test = 'test';
    /** The user's own domain; they add the DNS record Falak shows. */
    case Custom = 'custom';
}
