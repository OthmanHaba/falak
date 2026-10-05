<?php

namespace Falak\Fleet\Contracts;

enum AgentStatus: string
{
    case Online = 'online';
    case Offline = 'offline';
    case Revoked = 'revoked';
}
