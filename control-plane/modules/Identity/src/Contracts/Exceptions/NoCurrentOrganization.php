<?php

namespace Falak\Identity\Contracts\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class NoCurrentOrganization extends HttpException
{
    public function __construct()
    {
        parent::__construct(403, 'No organization selected.');
    }
}
