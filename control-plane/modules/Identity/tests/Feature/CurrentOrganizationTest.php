<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Kiln\Identity\Application\Actions\CreateApiToken;
use Kiln\Identity\Application\Actions\CreateOrganization;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Contracts\Exceptions\NoCurrentOrganization;
use Kiln\Identity\Domain\Models\User;
use Kiln\Identity\Infrastructure\ResolvedCurrentOrganization;

function freshCurrentOrganization(): CurrentOrganization
{
    app()->forgetScopedInstances();

    return app(CurrentOrganization::class);
}

function requestWithSession(array $session = []): void
{
    $request = Request::create('/');
    $store = app('session')->driver();
    $store->flush();
    $store->put($session);
    $request->setLaravelSession($store);
    app()->instance('request', $request);
}

beforeEach(function () {
    [$this->user, $this->first] = memberOf();
    $this->second = app(CreateOrganization::class)($this->user, 'Second');
    $this->third = app(CreateOrganization::class)($this->user, 'Third');
    $this->user->refresh();
    Auth::setUser($this->user);
});

it('is empty for guests', function () {
    Auth::forgetUser();
    app('auth')->forgetGuards();

    $current = freshCurrentOrganization();
    expect($current->id())->toBeNull()->and($current->get())->toBeNull();
    expect(fn () => $current->require())->toThrow(NoCurrentOrganization::class);
    expect(fn () => $current->requireId())->toThrow(NoCurrentOrganization::class);
});

it('resolves the token organization first', function () {
    $token = app(CreateApiToken::class)($this->user, $this->first->id, 't', ['*']);
    $this->user->withAccessToken($token->accessToken);
    requestWithSession([ResolvedCurrentOrganization::SESSION_KEY => $this->second->id]);

    expect(freshCurrentOrganization()->id())->toBe($this->first->id);
});

it('then the session selection, then the user column, then the first membership', function () {
    $this->user->forceFill(['current_organization_id' => $this->third->id])->save();

    requestWithSession([ResolvedCurrentOrganization::SESSION_KEY => $this->second->id]);
    expect(freshCurrentOrganization()->id())->toBe($this->second->id);

    requestWithSession();
    expect(freshCurrentOrganization()->id())->toBe($this->third->id);

    // Stale selections the user no longer belongs to are ignored.
    [, $foreign] = memberOf();
    requestWithSession([ResolvedCurrentOrganization::SESSION_KEY => $foreign->id]);
    $this->user->forceFill(['current_organization_id' => $foreign->id])->save();
    expect(freshCurrentOrganization()->id())->toBe($this->first->id);
});

it('exposes organization data', function () {
    $this->user->forceFill(['current_organization_id' => $this->second->id])->save();
    requestWithSession();

    $data = freshCurrentOrganization()->require();
    expect($data->id)->toBe($this->second->id)
        ->and($data->name)->toBe('Second')
        ->and($data->ownerId)->toBe($this->user->id)
        ->and($data->personal)->toBeFalse()
        ->and($data->toArray())->toBe(['id' => $this->second->id, 'name' => 'Second', 'slug' => 'second', 'personal' => false]);
});

it('runs a callback in another organization and restores the previous one', function () {
    $this->user->forceFill(['current_organization_id' => $this->second->id])->save();
    requestWithSession();
    $current = freshCurrentOrganization();

    $inside = $current->run($this->third->id, fn () => $current->id());

    expect($inside)->toBe($this->third->id)->and($current->id())->toBe($this->second->id);

    expect(fn () => $current->run('01JUNKNOWNORGANIZATION0000', fn () => null))->toThrow(ModelNotFoundException::class);
    expect($current->id())->toBe($this->second->id);
});

it('works without any authenticated user inside run()', function () {
    Auth::forgetUser();
    app('auth')->forgetGuards();
    $current = freshCurrentOrganization();

    expect($current->run($this->first->id, fn () => $current->requireId()))->toBe($this->first->id)
        ->and($current->id())->toBeNull();
});

it('returns null for users without organizations', function () {
    Auth::setUser(User::factory()->create());
    requestWithSession();

    expect(freshCurrentOrganization()->id())->toBeNull();
});
