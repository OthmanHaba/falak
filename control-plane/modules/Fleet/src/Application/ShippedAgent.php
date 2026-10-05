<?php

namespace Falak\Fleet\Application;

use Falak\Fleet\Infrastructure\AgentBinaries;
use Falak\Fleet\Infrastructure\PanelUrls;

/**
 * The falak-agent build this control plane hands out (installer and upgrades), per architecture, and how a running
 * agent compares to it.
 */
final class ShippedAgent
{
    public function __construct(
        private readonly AgentBinaries $binaries,
        private readonly PanelUrls $urls,
    ) {}

    /**
     * @return array{version: string, sha256: string, url: string}|null null when no verifiable build is published
     */
    public function for(?string $arch): ?array
    {
        if ($arch === null) {
            return null;
        }

        $sha = ((array) config('fleet.agent.checksums', []))[$arch] ?? ($this->urls->isLocalDownload() ? $this->binaries->checksum($arch) : null);
        $version = $this->binaries->version($arch) ?? (config('fleet.agent.version') ?: null);

        if (! is_string($sha) || preg_match('/^[a-f0-9]{64}$/', strtolower($sha)) !== 1 || $version === null) {
            return null;
        }

        return ['version' => (string) $version, 'sha256' => strtolower($sha), 'url' => $this->urls->agentDownload($arch)];
    }

    /**
     * Whether an agent (version and binary checksum from its facts) runs an older build than the shipped one. The
     * checksum decides when both are known (dev and CI builds share version strings), except that a newer release
     * than the shipped one never counts as outdated.
     */
    public static function outdated(?string $version, ?string $sha256, ?array $shipped): bool
    {
        if ($shipped === null || ($version === null && $sha256 === null)) {
            return false;
        }

        $running = self::semver($version);
        $available = self::semver($shipped['version']);

        if ($running !== null && $available !== null && version_compare($running, $available, '>')) {
            return false;
        }

        if ($sha256 !== null && $sha256 !== '') {
            return strtolower($sha256) !== $shipped['sha256'];
        }

        if ($running !== null && $available !== null) {
            return version_compare($running, $available, '<');
        }

        return $version !== $shipped['version'];
    }

    /** "v1.2.3" / "1.2.3-rc.1" → "1.2.3" / "1.2.3-rc.1"; null for non-release versions (dev, git describe, ci-…). */
    public static function semver(?string $version): ?string
    {
        if ($version === null || preg_match('/^v?(\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?)$/', $version, $m) !== 1) {
            return null;
        }

        return $m[1];
    }
}
