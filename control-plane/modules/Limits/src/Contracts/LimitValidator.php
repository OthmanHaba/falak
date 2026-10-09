<?php

namespace Falak\Limits\Contracts;

use Illuminate\Validation\ValidationException;

/**
 * Validates limits a user sets on a service: their shape ({@see ResourceLimits::rules()}), their consistency (the
 * reservation within the limit, max restarts only with on-failure) and the servers the service runs on (memory at
 * most the smallest server's RAM, CPUs at most its cores, from the agents' facts).
 */
interface LimitValidator
{
    /**
     * @param  array<string, mixed>|null  $input  the request's `limits` object (null = clear them)
     * @param  list<string>  $serverIds  the servers the service runs on
     * @param  string  $field  error key prefix
     *
     * @throws ValidationException
     */
    public function validate(?array $input, array $serverIds, string $field = 'limits'): ResourceLimits;
}
