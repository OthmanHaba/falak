<?php

namespace Falak\Servers\Application;

use Falak\Identity\Contracts\AuditLog;
use Falak\Servers\Domain\Enums\PhpVersionStatus;
use Falak\Servers\Domain\Models\PhpVersion;
use Falak\Servers\Domain\Models\Server;
use Illuminate\Support\Facades\DB;

/**
 * Fits the PHP versions a server should get to what its OS can install (servers.php_versions_by_os), once the
 * agent has reported the OS. The stack is chosen before that: a custom server created with the default PHP 8.4
 * turns out to run Ubuntu 26.04, which only has 8.5. Versions not installed yet that the OS cannot install are
 * dropped; when none is left, the highest installable version replaces them. Installed versions are kept.
 */
final class PhpVersionsForOs
{
    public function __construct(private readonly AuditLog $audit) {}

    /**
     * @return ?string a note for the server's status message when something changed
     */
    public function fit(Server $server): ?string
    {
        $installable = $server->installablePhpVersions();

        if ($server->stack->phpRuntime === null || $installable === array_values(array_map('strval', (array) config('servers.php_versions')))) {
            return null;
        }

        $pending = [PhpVersionStatus::Installing, PhpVersionStatus::Failed];
        $dropped = $server->phpVersions()->get()
            ->filter(fn (PhpVersion $php) => in_array($php->status, $pending, true) && ! in_array((string) $php->version, $installable, true));

        if ($dropped->isEmpty()) {
            return null;
        }

        $versions = $dropped->pluck('version')->map(fn ($v) => (string) $v)->sort()->values()->all();
        $replacement = null;

        DB::transaction(function () use ($server, $dropped, $installable, &$replacement) {
            $server->phpVersions()->whereKey($dropped->modelKeys())->delete();

            if ($server->desiredPhpVersions() === [] && $installable !== []) {
                usort($installable, 'version_compare');
                $replacement = (string) end($installable);
                $server->phpVersions()->create([
                    'version' => $replacement,
                    'status' => PhpVersionStatus::Installing,
                    'is_default' => true,
                    'ini' => PhpVersion::DEFAULT_INI,
                    'fpm' => PhpVersion::defaultFpm($server->memory_bytes),
                ]);
            }

            $desired = $server->desiredPhpVersions();
            $default = $server->phpVersions()->where('is_default', true)->whereIn('version', $desired)->value('version');

            if ($default === null && $desired !== []) {
                usort($desired, 'version_compare');
                $default = (string) end($desired);
                $server->phpVersions()->update(['is_default' => false]);
                $server->phpVersions()->where('version', $default)->update(['is_default' => true]);
            }

            $server->forceFill(['stack' => $server->stack->withPhp((string) $server->stack->phpRuntime, $desired, $default !== null ? (string) $default : null)])->save();
        });

        $os = $server->osLabel();
        $note = 'PHP '.implode(', ', $versions).' '.(count($versions) === 1 ? 'is' : 'are')." not available on {$os}";
        $note .= $replacement !== null ? "; installing PHP {$replacement} instead." : '; left out of the plan.';

        $this->audit->record('server.php_versions_adjusted', 'server', $server->id, ['removed' => $versions, 'added' => $replacement, 'os' => $server->os], $server->organization_id);

        return $note;
    }
}
