<?php

namespace Kiln\Deployments\Infrastructure;

use Kiln\Deployments\Contracts\Data\LiveRelease;
use Kiln\Deployments\Contracts\LiveReleases;
use Kiln\Deployments\Domain\Models\Release;
use Kiln\Deployments\Domain\Models\ServerRelease;

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
