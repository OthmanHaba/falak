<?php

namespace Falak\Network\Domain\Enums;

enum RuleProtocol: string
{
    case Tcp = 'tcp';
    case Udp = 'udp';
    case Any = 'any';
}
