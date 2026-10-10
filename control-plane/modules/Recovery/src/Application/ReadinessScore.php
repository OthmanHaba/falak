<?php

namespace Falak\Recovery\Application;

use Falak\Databases\Contracts\DatabaseRecovery;
use Falak\Projects\Contracts\Data\ProjectData;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Volumes\Contracts\VolumeRecovery;

/**
 * A project's DR readiness: every database backed up on a schedule (backups are always encrypted) and drilled, every
 * volume backed up on a schedule, PITR on for production SQL databases. The score is the share of checks that pass
 * (100 with nothing to protect); each gap links to where it is fixed.
 */
final class ReadinessScore
{
    public function __construct(
        private readonly ProjectDirectory $projects,
        private readonly DatabaseRecovery $databases,
        private readonly VolumeRecovery $volumes,
    ) {}

    /**
     * @return array{project_id: string, name: string, score: int, checks: int, passed: int, gaps: list<array{kind: string, id: string, name: string, environment: string, problem: string, fix: string, url: ?string}>}
     */
    public function forProject(ProjectData $project): array
    {
        $checks = 0;
        $passed = 0;
        $gaps = [];

        $check = function (bool $ok, string $kind, string $id, string $name, string $environment, string $problem, string $fix, ?string $url) use (&$checks, &$passed, &$gaps) {
            $checks++;

            if ($ok) {
                $passed++;

                return;
            }

            $gaps[] = compact('kind', 'id', 'name', 'environment', 'problem', 'fix', 'url');
        };

        foreach ($this->projects->environments($project->id) as $environment) {
            $services = $this->projects->servicesIn($environment->id);
            $databaseIds = [];
            $siteIds = [];

            foreach ($services as $service) {
                if ($service->kind === ServiceKind::Database) {
                    $databaseIds[] = $service->refId;
                } else {
                    $siteIds[] = $service->refId;
                }
            }

            foreach ($this->databases->points($databaseIds) as $point) {
                $url = $this->projects->serviceUrl(ServiceKind::Database, $point->databaseId, 'backups');
                $check($point->backupId !== null, 'database', $point->databaseId, $point->name, $environment->name,
                    'No restorable (encrypted) backup', 'Run a backup', $url);
                $check($point->scheduled, 'database', $point->databaseId, $point->name, $environment->name,
                    'No backup schedule', 'Add a backup schedule', $url);
                $check($point->drilledAt !== null, 'database', $point->databaseId, $point->name, $environment->name,
                    'Never restored in a drill', 'Turn on restore drills for its schedule', $url);

                if ($environment->isProduction && $point->pitrSupported) {
                    $check($point->pitrEnabled, 'database', $point->databaseId, $point->name, $environment->name,
                        'Point-in-time recovery is off', 'Turn on point-in-time recovery', $this->projects->serviceUrl(ServiceKind::Database, $point->databaseId, 'settings'));
                }
            }

            foreach ($this->volumes->forSites($siteIds) as $volume) {
                $url = "/volumes/{$volume->volumeId}";
                $check($volume->backupId !== null, 'volume', $volume->volumeId, $volume->name, $environment->name,
                    'No restorable (encrypted) backup', 'Back it up', $url);
                $check($volume->scheduled, 'volume', $volume->volumeId, $volume->name, $environment->name,
                    'No backup schedule', 'Add a backup schedule', $url);
            }
        }

        return [
            'project_id' => $project->id,
            'name' => $project->name,
            'score' => $checks === 0 ? 100 : (int) floor(100 * $passed / $checks),
            'checks' => $checks,
            'passed' => $passed,
            'gaps' => $gaps,
        ];
    }
}
