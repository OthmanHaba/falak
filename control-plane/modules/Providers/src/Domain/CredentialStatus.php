<?php

namespace Falak\Providers\Domain;

enum CredentialStatus: string
{
    case Active = 'active';
    case Invalid = 'invalid';
}
