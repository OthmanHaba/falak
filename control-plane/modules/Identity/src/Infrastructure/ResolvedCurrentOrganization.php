<?php

namespace Falak\Identity\Infrastructure;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\Data\OrganizationData;
use Falak\Identity\Contracts\Exceptions\NoCurrentOrganization;
use Falak\Identity\Domain\Models\Organization;
use Falak\Identity\Domain\Models\PersonalAccessToken;
use Falak\Identity\Domain\Models\User;

/**
 * Lazily resolves the current organization (request-scoped binding).
 */
final class ResolvedCurrentOrganization implements CurrentOrganization
{
    public const SESSION_KEY = 'identity.current_organization_id';

    private bool $resolved = false;

    private ?Organization $organization = null;

    public function __construct(
        private readonly Container $container,
        private readonly AuthFactory $auth,
    ) {}

    public function id(): ?string
    {
        return $this->model()?->id;
    }

    public function get(): ?OrganizationData
    {
        return $this->model()?->toData();
    }

    public function require(): OrganizationData
    {
        return $this->get() ?? throw new NoCurrentOrganization;
    }

    public function requireId(): string
    {
        return $this->id() ?? throw new NoCurrentOrganization;
    }

    public function run(string $organizationId, callable $callback): mixed
    {
        [$resolved, $organization] = [$this->resolved, $this->organization];

        $this->organization = Organization::query()->findOrFail($organizationId);
        $this->resolved = true;

        try {
            return $callback();
        } finally {
            [$this->resolved, $this->organization] = [$resolved, $organization];
        }
    }

    /**
     * Forget the resolved organization so the next access re-resolves (after switching).
     */
    public function forget(): void
    {
        $this->resolved = false;
        $this->organization = null;
    }

    public function model(): ?Organization
    {
        if (! $this->resolved) {
            $this->organization = $this->resolve();
            $this->resolved = true;
        }

        return $this->organization;
    }

    private function resolve(): ?Organization
    {
        $user = $this->auth->guard()->user();

        if (! $user instanceof User) {
            return null;
        }

        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            // API tokens are pinned to exactly one organization.
            return $token->organization_id ? $this->membership($user, $token->organization_id) : null;
        }

        $candidates = array_filter([$this->sessionSelection(), $user->current_organization_id]);

        foreach ($candidates as $candidate) {
            if ($organization = $this->membership($user, $candidate)) {
                return $organization;
            }
        }

        /** @var ?Organization */
        return $user->organizations()->orderBy('identity_memberships.created_at')->first();
    }

    private function sessionSelection(): ?string
    {
        $request = $this->container->bound('request') ? $this->container->make('request') : null;

        if ($request instanceof Request && $request->hasSession()) {
            $value = $request->session()->get(self::SESSION_KEY);

            return is_string($value) ? $value : null;
        }

        return null;
    }

    private function membership(User $user, string $organizationId): ?Organization
    {
        /** @var ?Organization */
        return $user->organizations()->whereKey($organizationId)->first();
    }
}
