<?php

namespace Falak\Sites\Contracts;

use Illuminate\Validation\ValidationException;
use Falak\Sites\Contracts\Data\DomainChoice;

/**
 * Site domains owned by Edge. Sites binds a null implementation; Edge (which owns domains, TLS and load balancers)
 * rebinds it — Sites never depends on Edge directly.
 */
interface SiteDomains
{
    /**
     * @param  list<string>  $siteIds
     * @return array<string, string> primary domain keyed by site id (sites without one are omitted)
     */
    public function primaryDomains(array $siteIds): array;

    /**
     * The host a domain choice gives a new site or compose public service: the generated name
     * (`<label>.<ip-with-dashes>.<suffix>`, pointing at the leader server), null for the test domain, or the
     * normalised custom domain. Null $choice applies the organization's default (test domain when configured, else a
     * generated name, else a custom domain is required).
     *
     * @param  list<string>  $serverIds  leader first
     * @param  ?string  $siteId  an existing site: a generated name points at its load balancer when it has one
     *
     * @throws ValidationException keyed $field
     */
    public function resolveChoice(string $organizationId, ?DomainChoice $choice, string $label, array $serverIds, string $field, ?string $siteId = null): ?string;

    /**
     * Route $name to a site that was just created (automatic TLS). Validated beforehand by resolveChoice.
     *
     * @throws ValidationException
     */
    public function attach(string $siteId, string $name): void;
}
