<?php

namespace Falak\Apm\Transport;

interface Transport
{
    /**
     * Send an OTLP/HTTP JSON body to the given signal path (e.g. "/v1/traces").
     * Must never throw; returns false when the payload was dropped.
     */
    public function send(string $path, string $body): bool;
}
