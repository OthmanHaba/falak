<?php

namespace Kiln\Deployments\Infrastructure;

use Kiln\Deployments\Contracts\RetainedImages;
use Kiln\Deployments\Domain\Enums\ReleaseStatus;
use Kiln\Deployments\Domain\Models\Release;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class EloquentRetainedImages implements RetainedImages
{
    public function images(): array
    {
        $images = [];

        $releases = Release::query()->whereIn('status', [ReleaseStatus::Pending, ReleaseStatus::Active, ReleaseStatus::Inactive])
            ->select(['id', 'image', 'compose'])->lazyById();

        foreach ($releases as $release) {
            /** @var Release $release */
            if (is_string($release->image) && $release->image !== '') {
                $images[$release->image] = true;
            }

            $yaml = is_array($release->compose) ? ($release->compose['yaml'] ?? null) : null;

            if (! is_string($yaml) || $yaml === '') {
                continue;
            }

            try {
                $doc = Yaml::parse($yaml);
            } catch (ParseException) {
                continue;
            }

            foreach ((array) (is_array($doc) ? ($doc['services'] ?? []) : []) as $service) {
                if (is_array($service) && is_string($service['image'] ?? null) && $service['image'] !== '') {
                    $images[$service['image']] = true;
                }
            }
        }

        return array_keys($images);
    }
}
