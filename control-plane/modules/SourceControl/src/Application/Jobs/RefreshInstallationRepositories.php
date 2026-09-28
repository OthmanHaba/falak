<?php

namespace Kiln\SourceControl\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Kiln\SourceControl\Contracts\Exceptions\SourceControlException;
use Kiln\SourceControl\Domain\Models\Connection;
use Kiln\SourceControl\Infrastructure\Providers\GitHubClient;

/**
 * Re-list a GitHub App installation's repositories after access changed on GitHub, so pickers and the
 * repository count are current.
 */
final class RefreshInstallationRepositories implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public string $connectionId) {}

    public function handle(GitHubClient $github): void
    {
        $connection = Connection::query()->find($this->connectionId);

        if (! $connection || ! $connection->isApp() || $connection->status !== 'active') {
            return;
        }

        try {
            $github->installationRepositories($connection, fresh: true);
        } catch (SourceControlException) {
            // Listed again on the next picker load.
        }
    }
}
