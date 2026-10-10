<?php

namespace Falak\Secrets\Contracts;

enum AccessorType: string
{
    case User = 'user';
    case Deployment = 'deployment';
    case Build = 'build';
    case ApiToken = 'api_token';
    case System = 'system';
}
