<?php

namespace Kiln\Projects\Application\Canvas;

use DateTimeInterface;
use Kiln\Deployments\Contracts\DeploymentDirectory;
use Kiln\Projects\Contracts\ServiceKind;
use Kiln\Projects\Domain\Models\Environment;
use Kiln\Projects\Domain\Models\Service;

/**
 * The canvas Activity rail (UI_DESIGN §4): recent deployments of the environment's sites and services added
 * to it, newest first.
 *
 * @phpstan-type ActivityItem array{id: string, type: string, service_id: string, service_name: string, kind: string, ref_id: string, status: string, title: string, detail: ?string, deployment_id: ?string, at: string}
 */
final class CanvasActivity
{
    public const LIMIT = 30;

    public function __construct(private readonly DeploymentDirectory $deployments) {}

    /**
     * @return list<ActivityItem>
     */
    public function for(Environment $environment): array
    {
        /** @var array<string, Service> $sites */
        $sites = [];
        $items = [];

        foreach ($environment->services()->get() as $service) {
            /** @var Service $service */
            if ($service->kind === ServiceKind::Site) {
                $sites[$service->ref_id] = $service;
            }

            $items[] = [
                'id' => "service-{$service->id}",
                'type' => 'service',
                'service_id' => $service->id,
                'service_name' => $service->name,
                'kind' => $service->kind->value,
                'ref_id' => $service->ref_id,
                'status' => 'active',
                'title' => "{$service->name} added",
                'detail' => null,
                'deployment_id' => null,
                'at' => $service->created_at->format(DateTimeInterface::ATOM),
            ];
        }

        foreach ($this->deployments->recentForSites(array_keys($sites), self::LIMIT) as $deployment) {
            $service = $sites[$deployment->siteId];
            $items[] = [
                'id' => "deployment-{$deployment->id}",
                'type' => 'deployment',
                'service_id' => $service->id,
                'service_name' => $service->name,
                'kind' => 'site',
                'ref_id' => $service->ref_id,
                'status' => $deployment->status,
                'title' => "{$service->name} · deployment #{$deployment->number}",
                'detail' => $deployment->message ?? ($deployment->commit !== null ? substr($deployment->commit, 0, 7) : null),
                'deployment_id' => $deployment->id,
                'at' => ($deployment->finishedAt ?? $deployment->startedAt ?? $deployment->createdAt)->format(DateTimeInterface::ATOM),
            ];
        }

        usort($items, fn (array $a, array $b) => strcmp($b['at'], $a['at']));

        return array_slice($items, 0, self::LIMIT);
    }
}
