<?php

namespace Falak\Secrets\Application\Actions;

use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Projects\Contracts\VariableReferences;
use Falak\Secrets\Contracts\SecretScope;
use Falak\Secrets\Domain\Models\Secret;
use Falak\Sites\Contracts\Exceptions\EnvironmentChanged;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteEnvironments;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Promote a site variable to a secret: its value moves into a new secret of the site's service, and the
 * variable becomes `${{ secrets.NAME }}`. Both happen in one transaction.
 */
final class PromoteVariable
{
    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly SiteEnvironments $environments,
        private readonly ProjectDirectory $projects,
        private readonly CreateSecret $create,
    ) {}

    public function __invoke(string $siteId, string $key, string $name, bool $sensitive, ?string $userId): Secret
    {
        $site = $this->sites->find($siteId);
        $service = $this->projects->projectOf(ServiceKind::Site, $siteId);

        if ($site === null || $service === null) {
            throw ValidationException::withMessages(['key' => 'The site is not part of a project environment, so it has no service secrets.']);
        }

        $environment = $this->sites->environment($site->id);
        $value = $environment?->variables[$key] ?? null;

        if ($environment === null || $value === null) {
            throw ValidationException::withMessages(['key' => "The site has no variable {$key}."]);
        }

        if (preg_match(VariableReferences::PATTERN, (string) $value) === 1) {
            throw ValidationException::withMessages(['key' => "{$key} contains a \${{ … }} reference; only plain values can be promoted."]);
        }

        return DB::transaction(function () use ($site, $service, $environment, $key, $name, $sensitive, $userId, $value) {
            $secret = ($this->create)($site->organizationId, SecretScope::Service, $service->id, [
                'name' => $name,
                'value' => (string) $value,
                'sensitive' => $sensitive,
                'description' => "Promoted from {$site->name}'s {$key} variable",
            ], $userId);

            try {
                $this->environments->set($site->id, [$key => '${{ '.VariableReferences::SECRETS.".{$name} }}"], $userId, 'site.environment_promoted', $environment->version);
            } catch (EnvironmentChanged $e) {
                throw ValidationException::withMessages(['key' => $e->getMessage().' Try again.']);
            }

            return $secret;
        });
    }
}
