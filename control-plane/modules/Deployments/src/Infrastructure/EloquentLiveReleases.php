<?php

namespace Falak\Deployments\Infrastructure;

use Falak\Deployments\Contracts\Data\LiveRelease;
use Falak\Deployments\Contracts\LiveReleases;
use Falak\Deployments\Domain\Models\Release;
use Falak\Deployments\Domain\Models\ServerRelease;

final class EloquentLiveReleases implements LiveReleases
{
    public function onServer(string $serverId): array
    {
        $rows = ServerRelease::query()->where('server_id', strtolower($serverId))->get();
        $releases = Release::query()->whereIn('id', $rows->pluck('release_id'))->get()->keyBy('id');
        $live = [];

        foreach ($rows as $row) {
            $release = $releases->get($row->release_id);

            if ($release === null) {
                continue;
            }

            $live[$row->site_id] = new LiveRelease(
                $row->site_id,
                $row->server_id,
                $release->id,
                $release->deployment_id,
                array_map('strval', $release->environment ?? []),
            );
        }

        return $live;
    }
}
