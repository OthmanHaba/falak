<?php

namespace Kiln\Fleet\Infrastructure;

use Illuminate\Contracts\Routing\UrlGenerator;

/**
 * Public URLs handed to servers/agents (independent of the current request host).
 */
final class PanelUrls
{
    public function __construct(
        private readonly UrlGenerator $url,
        private readonly ?string $panelUrl,
        private readonly ?string $apiUrl,
        private readonly ?string $downloadUrl,
    ) {}

    public function panel(): string
    {
        return rtrim($this->panelUrl ?: (string) config('app.url'), '/');
    }

    public function agentApi(): string
    {
        return rtrim($this->apiUrl ?: $this->panel().'/agent/v1', '/');
    }

    public function installScript(string $token): string
    {
        return $this->panel().'/install/'.$token;
    }

    /**
     * Download URL template containing the literal "${ARCH}" shell variable.
     */
    public function agentDownloadTemplate(): string
    {
        if ($this->downloadUrl) {
            return str_replace('{arch}', '${ARCH}', $this->downloadUrl);
        }

        return $this->panel().'/install/agent/linux-${ARCH}';
    }

    /** Download URL of the agent build for one architecture (amd64|arm64). */
    public function agentDownload(string $arch): string
    {
        if ($this->downloadUrl) {
            return str_replace('{arch}', $arch, $this->downloadUrl);
        }

        return $this->panel().'/install/agent/linux-'.$arch;
    }

    public function isLocalDownload(): bool
    {
        return ! $this->downloadUrl;
    }
}
