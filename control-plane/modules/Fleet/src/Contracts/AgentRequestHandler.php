<?php

namespace Falak\Fleet\Contracts;

use Falak\Fleet\Contracts\Data\AgentCaller;
use Falak\Fleet\Contracts\Exceptions\AgentRequestRefused;
use Illuminate\Validation\ValidationException;

/**
 * Answers one type of agent request ({@see AgentRequests}). The caller is the authenticated agent: a handler only ever
 * acts on what belongs to its server and organization.
 */
interface AgentRequestHandler
{
    /**
     * @param  array<string, mixed>  $body  the request, already valid against its schema
     * @return array<string, mixed> the JSON reply
     *
     * @throws AgentRequestRefused (409 with a reason code)
     * @throws ValidationException (422)
     */
    public function handle(AgentCaller $caller, array $body): array;
}
