<?php

namespace Falak\Secrets\Application\Actions;

use Falak\Projects\Contracts\Data\ServiceData;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Projects\Contracts\VariableReferences;
use Falak\Secrets\Contracts\SecretScope;
use Falak\Secrets\Domain\Models\Secret;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\Exceptions\EnvironmentChanged;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteEnvironments;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Promote a site variable to a secret: its value moves into a new secret of the site's service, and the
 * variable becomes `${{ secrets.NAME }}` — in the current version and in every older one, so restoring or
 * revealing an old version can't bring the value back. All in one transaction.
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

        return $this->move($site, $service, $environment->version, $key, $name, $sensitive, $userId, (string) $value);
    }

    private function move(SiteData $site, ServiceData $service, int $baseVersion, string $key, string $name, bool $sensitive, ?string $userId, #[\SensitiveParameter] string $value): Secret
    {
        return DB::transaction(function () use ($site, $service, $baseVersion, $key, $name, $sensitive, $userId, $value) {
            $secret = ($this->create)($site->organizationId, SecretScope::Service, $service->id, [
                'name' => $name,
                'value' => $value,
                'sensitive' => $sensitive,
                'description' => "Promoted from {$site->name}'s {$key} variable",
            ], $userId);

            $reference = '${{ '.VariableReferences::SECRETS.".{$name} }}";

            try {
                $this->environments->set($site->id, [$key => $reference], $userId, 'site.environment_promoted', $baseVersion);
            } catch (EnvironmentChanged $e) {
                throw ValidationException::withMessages(['key' => $e->getMessage().' Try again.']);
            }

            $this->environments->redact($site->id, $key, $reference);

            return $secret;
        });
    }
}
