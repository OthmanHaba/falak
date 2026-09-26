<?php

namespace Kiln\Kernel\Http;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Base controller for module controllers. Controllers stay thin:
 * validate → call an Action → return a Resource / Inertia response.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
