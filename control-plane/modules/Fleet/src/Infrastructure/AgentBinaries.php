<?php

namespace Kiln\Fleet\Infrastructure;

/**
 * kiln-agent release binaries served by the control plane (binaries_path/kiln-agent-linux-<arch>).
 */
final class AgentBinaries
{
    public function __construct(private readonly string $path) {}

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
