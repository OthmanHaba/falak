<?php

namespace Falak\Templates\Application\Import;

/**
 * Fetches a template file from a user-supplied URL, server side (Settings → Templates → Import from URL).
 * Implementations must guard against SSRF: https only, public addresses only, bounded size.
 */
interface RemoteFetcher
{
    /**
     * @throws FetchFailed
     */
    public function fetch(string $url): string;
}
