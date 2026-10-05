<?php

namespace Falak\Sites\Infrastructure;

use Falak\Sites\Application\Actions\CreateSite;
use Falak\Sites\Application\Actions\DeleteSite;
use Falak\Sites\Application\Actions\DuplicateSite;
use Falak\Sites\Application\OctanePorts;
use Falak\Sites\Contracts\Data\CreatedSite;
use Falak\Sites\Contracts\Data\SitePlacement;
use Falak\Sites\Contracts\SiteFactory;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Http\Requests\StoreSiteRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ActionSiteFactory implements SiteFactory
{
    public function create(string $organizationId, ?string $userId, array $data, ?SitePlacement $placement = null): CreatedSite
    {
        $validated = Validator::make($data, StoreSiteRequest::rulesFor($organizationId), StoreSiteRequest::errorMessages())->validate();

        $create = app(CreateSite::class);
        $site = $create($organizationId, $userId, $validated, $placement);

        return new CreatedSite($site->toData(), array_values($create->warnings));
    }

    public function duplicate(string $siteId, array $overrides = [], ?SitePlacement $placement = null, ?string $userId = null): CreatedSite
    {
        $source = Site::query()->find($siteId) ?? throw ValidationException::withMessages(['site_id' => 'Unknown site.']);

        if (isset($overrides['name'])) {
            $rules = StoreSiteRequest::rulesFor($source->organization_id);
            Validator::make($overrides, ['name' => $rules['name']], StoreSiteRequest::errorMessages())->validate();
        }

        $create = app(CreateSite::class);
        $site = (new DuplicateSite($create, app(OctanePorts::class)))($source, $overrides, $placement, $userId);

        return new CreatedSite($site->toData(), array_values($create->warnings));
    }

    public function delete(string $siteId, bool $deleteVolumes = false): void
    {
        if ($site = Site::query()->find(strtolower($siteId))) {
            app(DeleteSite::class)($site, deleteVolumes: $deleteVolumes);
        }
    }
}
