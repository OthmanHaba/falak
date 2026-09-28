<?php

namespace Kiln\Fleet\Infrastructure;

/**
 * kiln-agent release binaries served by the control plane (binaries_path/kiln-agent-linux-<arch>).
 */
final class AgentBinaries
{
    public function __construct(
        private readonly string $path,
        private readonly ?string $version = null,
    ) {}

    /**
     * Version of the shipped build: the `kiln-agent-linux-<arch>.version` sidecar written by `make agent`, else the
     * configured version (KILN_AGENT_VERSION / KILN_VERSION). Null when no build is published for the arch.
     */
    public function version(string $arch): ?string
    {
        $file = $this->file($arch);

        if ($file === null) {
            return null;
        }

        if (is_file("{$file}.version") && ($v = trim((string) file_get_contents("{$file}.version"))) !== '') {
            return mb_substr($v, 0, 64);
        }

        return $this->version !== null && $this->version !== '' ? $this->version : null;
    }

    public function file(string $arch): ?string
    {
        if (! in_array($arch, InstallScript::ARCHES, true)) {
            return null;
        }

        $file = rtrim($this->path, '/')."/kiln-agent-linux-{$arch}";

        return is_file($file) ? $file : null;
    }

    public function checksum(string $arch): ?string
    {
        $file = $this->file($arch);

        if ($file === null) {
            return null;
        }

        $sidecar = "{$file}.sha256";

        if (is_file($sidecar) && filemtime($sidecar) >= filemtime($file)) {
            $sum = strtolower(trim(explode(' ', (string) file_get_contents($sidecar))[0]));

            if (preg_match('/^[a-f0-9]{64}$/', $sum)) {
                return $sum;
            }
        }

        return hash_file('sha256', $file) ?: null;
    }
}
