<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kiln\Identity\Contracts\Role;
use Kiln\Recipes\Domain\Models\Recipe;
use Kiln\Recipes\Infrastructure\BuiltinRecipes;
use Tests\Support\FakeAgentGateway;

beforeEach(function () {
    FakeAgentGateway::install();
});

it('lists organization recipes and built-ins', function () {
    [, $organization] = actingAsMember(Role::Viewer);
    Recipe::query()->create(['organization_id' => $organization->id, 'name' => 'Deploy check', 'script' => 'uptime', 'user' => 'root']);
    Recipe::query()->create(['organization_id' => (string) Str::ulid(), 'name' => 'Other org', 'script' => 'id', 'user' => 'root']);

    $this->get('/recipes')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Recipes/Index', false)
        ->has('recipes', 1)
        ->where('recipes.0.name', 'Deploy check')
        ->has('builtins', count(app(BuiltinRecipes::class)->all()))
        ->where('can.manage', false)
        ->where('can.run', false));
});

it('creates, updates and deletes recipes with audit entries', function () {
    [, $organization] = actingAsMember(Role::Developer);

    $this->post('/recipes', ['name' => 'Tail logs', 'script' => "cd /var/log\r\ntail -n 50 syslog", 'user' => 'root'])->assertSessionHasNoErrors();
    $recipe = Recipe::query()->firstOrFail();
    expect($recipe->organization_id)->toBe($organization->id)
        ->and($recipe->script)->toBe("cd /var/log\ntail -n 50 syslog");

    $this->put("/recipes/{$recipe->id}", ['name' => 'Tail logs', 'script' => 'tail /var/log/kern.log', 'user' => 'kiln', 'description' => 'kernel'])->assertSessionHasNoErrors();
    expect($recipe->refresh()->user)->toBe('kiln')->and($recipe->description)->toBe('kernel');

    $this->delete("/recipes/{$recipe->id}")->assertRedirect('/recipes');
    expect(Recipe::query()->count())->toBe(0);

    $actions = DB::table('identity_audit_log')->where('organization_id', $organization->id)->pluck('action')->all();
    expect($actions)->toContain('recipe.created', 'recipe.updated', 'recipe.deleted');
    expect(DB::table('identity_audit_log')->where('action', 'recipe.created')->value('context'))->not->toContain('tail');
});

it('validates recipes', function () {
    actingAsMember(Role::Developer);

    $this->post('/recipes', ['name' => '', 'script' => '   ', 'user' => 'Root!'])->assertSessionHasErrors(['name', 'script', 'user']);
    $this->post('/recipes', ['name' => 'x', 'script' => str_repeat('a', 65537), 'user' => 'root'])->assertSessionHasErrors('script');

    $this->post('/recipes', ['name' => 'Dup', 'script' => 'id', 'user' => 'root'])->assertSessionHasNoErrors();
    $this->post('/recipes', ['name' => 'Dup', 'script' => 'id', 'user' => 'root'])->assertSessionHasErrors('name');
});

it('keeps viewers read-only and hides other organizations', function () {
    [, $organization] = actingAsMember(Role::Viewer);
    $recipe = Recipe::query()->create(['organization_id' => $organization->id, 'name' => 'Mine', 'script' => 'id', 'user' => 'root']);

    $this->post('/recipes', ['name' => 'New', 'script' => 'id', 'user' => 'root'])->assertForbidden();
    $this->put("/recipes/{$recipe->id}", ['name' => 'Mine', 'script' => 'whoami', 'user' => 'root'])->assertForbidden();
    $this->delete("/recipes/{$recipe->id}")->assertForbidden();
    $this->post('/recipes/builtin/disk-usage/copy')->assertForbidden();

    actingAsMember(Role::Owner);
    $this->put("/recipes/{$recipe->id}", ['name' => 'Mine', 'script' => 'whoami', 'user' => 'root'])->assertNotFound();
    $this->delete("/recipes/{$recipe->id}")->assertNotFound();
    $this->get("/recipes/{$recipe->id}/run")->assertNotFound();
});

it('copies built-ins into the organization with unique names', function () {
    [, $organization] = actingAsMember(Role::Developer);

    $this->post('/recipes/builtin/disk-usage/copy')->assertSessionHasNoErrors();
    $this->post('/recipes/builtin/disk-usage/copy')->assertSessionHasNoErrors();
    $this->post('/recipes/builtin/nope/copy')->assertNotFound();

    $names = Recipe::query()->where('organization_id', $organization->id)->orderBy('name')->pluck('name')->all();
    expect($names)->toBe(['Disk usage report', 'Disk usage report (2)'])
        ->and(Recipe::query()->first()->script)->toBe(app(BuiltinRecipes::class)->find('disk-usage')->script);
});
