<?php

use Kiln\Identity\Contracts\Role;
use Kiln\Recipes\Domain\Models\Recipe;
use Kiln\Servers\Domain\Models\Server;
use Tests\Support\FakeAgentGateway;

beforeEach(function () {
    FakeAgentGateway::install();
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
    $this->server = Server::factory()->create(['organization_id' => $this->organization->id, 'name' => 'web-1']);
    $this->other = Server::factory()->create(['organization_id' => $this->organization->id, 'name' => 'web-2']);
    $this->recipe = Recipe::query()->create(['organization_id' => $this->organization->id, 'name' => 'Uptime', 'script' => 'uptime', 'user' => 'kiln']);
});

it('renders the recipes tab with runnable recipes and the history on that server', function () {
    $this->post("/recipes/{$this->recipe->id}/runs", ['server_ids' => [$this->server->id]])->assertSessionHasNoErrors();
    $this->post("/recipes/{$this->recipe->id}/runs", ['server_ids' => [$this->other->id]])->assertSessionHasNoErrors();

    $this->get("/servers/{$this->server->id}/recipes")->assertOk()->assertInertia(fn ($page) => $page
        ->component('Recipes/Server', false)
        ->where('server.id', $this->server->id)
        ->where('recipes.0.name', 'Uptime')
        ->where('recipes.0.run_url', fn (string $url) => str_ends_with($url, "/recipes/{$this->recipe->id}/run?server={$this->server->id}"))
        ->has('builtins')
        ->has('runs', 1)
        ->where('runs.0.target.server_id', $this->server->id)
        ->where('can.run', true));
});

it('preselects servers passed in the query on the run page', function () {
    $this->get("/recipes/{$this->recipe->id}/run?server={$this->server->id},01JUNKJUNKJUNKJUNKJUNKJUNK")->assertOk()->assertInertia(fn ($page) => $page
        ->component('Recipes/Run', false)
        ->where('preselected', [$this->server->id]));
});

it('hides the recipes tab of other organizations', function () {
    [$stranger] = memberOf();

    $this->actingAs($stranger)->get("/servers/{$this->server->id}/recipes")->assertNotFound();
});
