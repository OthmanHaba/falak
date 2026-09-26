<?php

namespace Kiln\Recipes\Infrastructure;

use Kiln\Recipes\Domain\BuiltinRecipe;

/**
 * Starter recipes available to every organization (runnable as-is, or copied and customised).
 */
final class BuiltinRecipes
{
    /**
     * @return array<string, BuiltinRecipe>
     */
    public function all(): array
    {
        $recipes = [
            new BuiltinRecipe(
                'clear-caches',
                'Clear system caches',
                'Flush filesystem buffers, drop the page cache and clean the apt package cache.',
                <<<'SH'
                set -euo pipefail
                sync
                echo 3 > /proc/sys/vm/drop_caches
                apt-get clean
                journalctl --vacuum-time=7d || true
                free -m
                SH,
            ),
            new BuiltinRecipe(
                'install-package',
                'Install apt package',
                'Install one or more Ubuntu packages (space separated) with apt-get.',
                <<<'SH'
                set -euo pipefail
                if [[ ! "${PACKAGE:-}" =~ ^[a-z0-9][a-z0-9+.:-]*( [a-z0-9][a-z0-9+.:-]*)*$ ]]; then
                  echo "PACKAGE must be a space-separated list of package names" >&2
                  exit 2
                fi
                export DEBIAN_FRONTEND=noninteractive
                apt-get update -qq
                # shellcheck disable=SC2086
                apt-get install -y --no-install-recommends $PACKAGE
                SH,
                variables: ['PACKAGE' => 'Package name(s), e.g. "htop ncdu"'],
            ),
            new BuiltinRecipe(
                'disk-usage',
                'Disk usage report',
                'Filesystem usage plus the largest directories under /var, /srv and /home.',
                <<<'SH'
                set -uo pipefail
                df -h -x tmpfs -x devtmpfs -x squashfs
                echo
                echo "Largest directories:"
                du -xh --max-depth=2 /var /srv /home 2>/dev/null | sort -rh | head -n 20
                SH,
            ),
            new BuiltinRecipe(
                'memory-report',
                'Memory report',
                'Memory and swap usage plus the top processes by resident memory.',
                <<<'SH'
                set -uo pipefail
                free -m
                echo
                ps -eo pid,user,rss,pcpu,comm --sort=-rss | head -n 15
                SH,
            ),
            new BuiltinRecipe(
                'listening-ports',
                'Listening ports',
                'Sockets listening for TCP and UDP connections, with owning processes.',
                "ss -tulpn\n",
            ),
            new BuiltinRecipe(
                'restart-service',
                'Restart a service',
                'Restart a systemd unit and print its status.',
                <<<'SH'
                set -euo pipefail
                if [[ ! "${SERVICE:-}" =~ ^[A-Za-z0-9@._-]+$ ]]; then
                  echo "SERVICE must be a systemd unit name" >&2
                  exit 2
                fi
                systemctl restart "$SERVICE"
                systemctl --no-pager status "$SERVICE" | head -n 20
                SH,
                variables: ['SERVICE' => 'systemd unit, e.g. "nginx" or "redis-server"'],
            ),
        ];

        return array_column(array_map(fn (BuiltinRecipe $r) => ['k' => $r->key, 'r' => $r], $recipes), 'r', 'k');
    }

    public function find(string $key): ?BuiltinRecipe
    {
        return $this->all()[$key] ?? null;
    }
}
