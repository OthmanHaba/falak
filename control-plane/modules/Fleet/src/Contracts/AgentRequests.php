<?php

namespace Falak\Fleet\Contracts;

/**
 * Requests agents send to the control plane (POST /agent/v1/requests/<type>; contracts/agent-protocol/requests): Fleet
 * authenticates the agent, validates the body against requests/<type>.schema.json when there is one, and hands it to
 * the module that registered the type. Example: Databases answers pitr.upload_urls with presigned URLs.
 */
interface AgentRequests
{
    /**
     * @param  string  $type  dotted, e.g. "pitr.upload_urls"
     * @param  class-string<AgentRequestHandler>  $handler  resolved from the container per request
     */
    public function register(string $type, string $handler): void;

    /** @return ?class-string<AgentRequestHandler> */
    public function handler(string $type): ?string;
}
