<?php

namespace Falak\Apm\Transport;

final class NullTransport implements Transport
{
    public function send(string $path, string $body): bool
    {
        return true;
    }
}
