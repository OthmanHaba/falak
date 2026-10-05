<?php

namespace Falak\Identity\Application\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Domain\Models\User;
use Falak\Identity\Infrastructure\ResolvedCurrentOrganization;

final class SwitchOrganization
{
    public function __construct(
        private readonly Container $container,
        private readonly CurrentOrganization $current,
    ) {}

    public function __invoke(User $user, string $organizationId): void
    {
        if (! $user->belongsToOrganization($organizationId)) {
            throw new AuthorizationException('You are not a member of that organization.');
        }

        $user->forceFill(['current_organization_id' => $organizationId])->save();

        $request = $this->container->bound('request') ? $this->container->make('request') : null;

        if ($request instanceof Request && $request->hasSession()) {
            $request->session()->put(ResolvedCurrentOrganization::SESSION_KEY, $organizationId);
        }

        if ($this->current instanceof ResolvedCurrentOrganization) {
            $this->current->forget();
        }
    }
}
