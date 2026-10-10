<?php

namespace Falak\Secrets\Application;

use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\VariableReferences;
use Falak\Secrets\Domain\Models\Secret;
use Falak\Sites\Contracts\SiteDirectory;

/**
 * "Used by": the site services whose current variables reference a secret by name and resolve that name to it
 * (no nearer secret of the same name shadows it).
 */
final class SecretUsage
{
    public function __construct(
        private readonly Scopes $scopes,
        private readonly SecretLookup $lookup,
        private readonly SiteDirectory $sites,
        private readonly ProjectDirectory $projects,
        private readonly VariableReferences $references,
    ) {}

    /**
     * @param  list<Secret>  $secrets  of one organization
     * @return array<string, list<array{service_id: string, name: string, site_id: string, variables: list<string>, url: ?string}>> secret id => users
     */
    public function of(array $secrets): array
    {
        $usage = array_fill_keys(array_map(fn (Secret $secret) => $secret->id, $secrets), []);
        $services = [];

        foreach ($secrets as $secret) {
            foreach ($this->scopes->siteServicesUnder($secret->organization_id, $secret->scope_type, $secret->scope_id) as $service) {
                $services[$service->id] = $service;
            }
        }

        foreach ($services as $service) {
            $variables = array_map('strval', $this->sites->environment($service->refId)->variables ?? []);
            $byName = [];

            foreach ($this->references->referencesIn($variables) as $reference) {
                if (strtolower(trim($reference['service'])) === VariableReferences::SECRETS) {
                    $byName[$reference['key']][] = $reference['variable'];
                }
            }

            if ($byName === []) {
                continue;
            }

            foreach ($this->lookup->nearest(Scopes::chainForService($service), array_keys($byName)) as $name => $secret) {
                if (array_key_exists($secret->id, $usage)) {
                    $usage[$secret->id][] = [
                        'service_id' => $service->id,
                        'name' => $service->name,
                        'site_id' => $service->refId,
                        'variables' => array_values(array_unique($byName[$name])),
                        'url' => $this->projects->serviceUrl($service->kind, $service->refId, 'variables'),
                    ];
                }
            }
        }

        return $usage;
    }
}
