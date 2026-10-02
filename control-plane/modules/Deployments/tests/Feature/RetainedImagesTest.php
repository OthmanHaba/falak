<?php

use Illuminate\Support\Str;
use Kiln\Deployments\Contracts\RetainedImages;
use Kiln\Deployments\Domain\Enums\ReleaseStatus;
use Kiln\Deployments\Domain\Models\Release;

/*
 * Images releases may still run (the registry cleanup keeps them): Docker site images and compose `image:`s of
 * pending, live and rollback releases — not of failed or pruned ones.
 */

function retained_release(ReleaseStatus $status, ?string $image = null, ?string $yaml = null): Release
{
    return Release::query()->create([
        'id' => strtolower((string) Str::ulid()), 'organization_id' => strtolower((string) Str::ulid()), 'site_id' => strtolower((string) Str::ulid()),
        'deployment_id' => strtolower((string) Str::ulid()), 'image' => $image, 'status' => $status,
        'compose' => $yaml === null ? null : ['yaml' => $yaml, 'env' => [], 'leader' => [], 'source' => 'repo'],
    ]);
}

it('lists the images of releases that may still run', function () {
    $digest = 'sha256:'.str_repeat('a', 64);
    retained_release(ReleaseStatus::Active, 'registry.test/kiln/site:01j9zq4n8v2m6r0t3w5y7b9d1f@'.$digest);
    retained_release(ReleaseStatus::Inactive, null, "services:\n  api:\n    image: registry.test/kiln/shop/api@{$digest}\n  db:\n    image: postgres:17\n  broken: nope\n");
    retained_release(ReleaseStatus::Pending, 'registry.test/kiln/next:01j9zq4n8v2m6r0t3w5y7b9d1g');
    retained_release(ReleaseStatus::Failed, 'registry.test/kiln/failed:x');
    retained_release(ReleaseStatus::Pruned, null, "services:\n  api:\n    image: registry.test/kiln/old/api:y\n");
    retained_release(ReleaseStatus::Active, null, 'not: [valid');

    expect(app(RetainedImages::class)->images())->toEqualCanonicalizing([
        'registry.test/kiln/site:01j9zq4n8v2m6r0t3w5y7b9d1f@'.$digest,
        "registry.test/kiln/shop/api@{$digest}",
        'postgres:17',
        'registry.test/kiln/next:01j9zq4n8v2m6r0t3w5y7b9d1g',
    ]);
});
