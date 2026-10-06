<?php

namespace Falak\Secrets\Domain\Enums;

enum ProviderStatus: string
{
    case Untested = 'untested';
    case Ok = 'ok';
    case Error = 'error';
}
