<?php

use Falak\Fleet\Events\CommandFinished;
use Falak\Fleet\Infrastructure\ProtocolSchemas;
use Falak\Identity\Contracts\Role;
use Falak\Recipes\Domain\Enums\RunStatus;
use Falak\Recipes\Domain\Enums\TargetStatus;
use Falak\Recipes\Domain\Models\Recipe;
use Falak\Recipes\Domain\Models\Run;
use Falak\Recipes\Events\RecipeRunFinished;
use Falak\Recipes\Events\RecipeRunUpdated;
use Falak\Recipes\Http\Channels\RunChannel;
use Falak\Servers\Contracts\ServerStatus;
use Falak\Servers\Domain\Models\Server;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeAgentGateway;

beforeEach(function () {
    $this->agents = FakeAgentGateway::install();
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
    $this->servers = collect(['web-1', 'web-2', 'web-3'])->map(fn (string $name) => Server::factory()->create(['organization_id' => $this->organization->id, 'name' => $name]));
    $this->recipe = Recipe::query()->create(['organization_id' => $this->organization->id, 'name' => 'Uptime', 'script' => 'uptime', 'user' => 'falak']);
});

function recipes_run(array $serverIds, array $extra = []): Run
{
    test()->post('/recipes/'.test()->recipe->id.'/runs', ['server_ids' => $serverIds, ...$extra])->assertSessionHasNoErrors()->assertRedirect();

    return Run::query()->latest()->orderByDesc('id')->firstOrFail();
}

it('fans a recipe out to every selected server with schema-valid system.exec payloads', function () {
    Event::fake([RecipeRunUpdated::class]);

    $run = recipes_run($this->servers->pluck('id')->all(), ['env' => [['name' => 'APP_ENV', 'value' => 'production']], 'timeout' => 120]);

    $commands = $this->agents->dispatched('system.exec');
    expect($commands)->toHaveCount(3)
        ->and(collect($commands)->map(fn ($c) => $c['handle']->serverId)->sort()->values()->all())->toBe($this->servers->pluck('id')->sort()->values()->all());

    foreach ($commands as $command) {
        expect($command['payload'])->toBe(['script' => 'uptime', 'shell' => '/bin/bash', 'user' => 'falak', 'env' => ['APP_ENV' => 'production']])
            ->and($command['timeout'])->toBe(120)
            ->and($command['handle']->idempotencyKey)->toStartWith('recipes.run:')
            ->and(app(ProtocolSchemas::class)->validateCommand('system.exec', ProtocolSchemas::toJson($command['payload'])))->toBe([]);
    }

    expect($run->status)->toBe(RunStatus::Pending)
        ->and($run->targets()->whereNotNull('command_id')->count())->toBe(3)
        ->and($run->env)->toBe(['APP_ENV' => 'production'])
        ->and(DB::table('recipes_runs')->value('env'))->not->toContain('production');

    Event::assertDispatched(RecipeRunUpdated::class, fn ($e) => $e->runId === $run->id && $e->broadcastOn()->name === "private-recipes.runs.{$run->id}");

    $audit = DB::table('identity_audit_log')->where('action', 'recipe.run')->first();
    expect($audit->subject_id)->toBe($run->id)
        ->and($audit->context)->toContain('APP_ENV')->not->toContain('production');
});

it('rolls target outcomes up into the run status', function () {
    Event::fake([RecipeRunFinished::class, RecipeRunUpdated::class]);
    $run = recipes_run($this->servers->pluck('id')->all());
    [$a, $b, $c] = array_map(fn ($c) => $c['handle'], $this->agents->dispatched('system.exec'));

    $this->agents->emit($a, "up 3 days\n");
    expect($run->refresh()->status)->toBe(RunStatus::Running)
        ->and($run->targets()->where('command_id', $a->id)->value('status'))->toBe(TargetStatus::Running);

    $this->agents->succeed($a, ['exit_code' => 0, 'duration_ms' => 42]);
    $this->agents->succeed($b, ['exit_code' => 0, 'duration_ms' => 7]);
    expect($run->refresh()->status)->toBe(RunStatus::Running);
    Event::assertNotDispatched(RecipeRunFinished::class);

    $this->agents->fail($c, 'bash: uptime: command not found', 127);

    expect($run->refresh()->status)->toBe(RunStatus::Partial)
        ->and($run->finished_at)->not->toBeNull();

    $target = $run->targets()->where('command_id', $a->id)->first();
    expect($target->status)->toBe(TargetStatus::Succeeded)->and($target->duration_ms)->toBe(42)->and($target->exit_code)->toBe(0);

    $failed = $run->targets()->where('command_id', $c->id)->first();
    expect($failed->status)->toBe(TargetStatus::Failed)->and($failed->exit_code)->toBe(127)->and($failed->error)->toContain('command not found');

    Event::assertDispatchedTimes(RecipeRunFinished::class, 1);
    Event::assertDispatched(RecipeRunFinished::class, fn ($e) => $e->runId === $run->id && $e->status === 'partial' && $e->succeeded === 2 && $e->failed === 1 && $e->total === 3 && $e->organizationId === $this->organization->id);

    // A duplicate outcome does not re-announce.
    $this->agents->succeed($a, ['exit_code' => 0]);
    Event::assertDispatchedTimes(RecipeRunFinished::class, 1);
});

it('marks the run succeeded or failed when every target agrees', function () {
    Event::fake([RecipeRunFinished::class]);

    $ok = recipes_run([$this->servers[0]->id]);
    $this->agents->succeed($this->agents->last('system.exec')['handle'], ['exit_code' => 0]);
    expect($ok->refresh()->status)->toBe(RunStatus::Succeeded);

    $bad = recipes_run([$this->servers[1]->id]);
    $this->agents->fail($this->agents->last('system.exec')['handle'], null, null, 'timed_out');
    expect($bad->refresh()->status)->toBe(RunStatus::Failed)
        ->and($bad->targets()->first()->error)->toBe('Timed out.');

    Event::assertDispatched(RecipeRunFinished::class, fn ($e) => $e->runId === $ok->id && $e->status === 'succeeded');
    Event::assertDispatched(RecipeRunFinished::class, fn ($e) => $e->runId === $bad->id && $e->status === 'failed');
});

it('marks servers without a connected agent as unavailable', function () {
    $this->agents->unavailable($this->servers[1]->id);

    $run = recipes_run($this->servers->take(2)->pluck('id')->all());

    $unavailable = $run->targets()->where('server_id', $this->servers[1]->id)->first();
    expect($unavailable->status)->toBe(TargetStatus::Unavailable)
        ->and($unavailable->command_id)->toBeNull()
        ->and($this->agents->dispatched('system.exec'))->toHaveCount(1);

    $this->agents->succeed($this->agents->last('system.exec')['handle']);
    expect($run->refresh()->status)->toBe(RunStatus::Partial);
});

it('ignores unrelated commands and other organizations', function () {
    $run = recipes_run([$this->servers[0]->id]);
    $handle = $this->agents->last('system.exec')['handle'];

    event(new CommandFinished($handle->id, 'another-org', $handle->serverId, 'system.exec', $handle->idempotencyKey, 0, null));
    expect($run->refresh()->status)->toBe(RunStatus::Pending);

    $other = $this->agents->dispatch($this->servers[0]->id, 'system.exec', ['script' => 'id']);
    $this->agents->succeed($other);
    expect($run->refresh()->status)->toBe(RunStatus::Pending);
});

it('runs built-in recipes with variables', function () {
    $this->post('/recipes/builtin/install-package/runs', [
        'server_ids' => [$this->servers[0]->id],
        'env' => [['name' => 'PACKAGE', 'value' => 'htop']],
    ])->assertSessionHasNoErrors();

    $run = Run::query()->firstOrFail();
    expect($run->builtin)->toBe('install-package')
        ->and($run->recipe_id)->toBeNull()
        ->and($run->user)->toBe('root')
        ->and($this->agents->last('system.exec')['payload']['env'])->toBe(['PACKAGE' => 'htop'])
        ->and($this->agents->last('system.exec')['payload']['script'])->toContain('apt-get install');

    $this->get('/recipes/builtin/install-package/run')->assertOk()->assertInertia(fn ($page) => $page->component('Recipes/Run', false)->where('recipe.builtin', true)->has('servers', 3));
    $this->post('/recipes/builtin/nope/runs', ['server_ids' => [$this->servers[0]->id]])->assertNotFound();
});

it('validates run input', function () {
    $foreign = Server::factory()->create();
    $deleting = Server::factory()->status(ServerStatus::Deleting)->create(['organization_id' => $this->organization->id]);
    $url = "/recipes/{$this->recipe->id}/runs";

    $this->post($url, ['server_ids' => []])->assertSessionHasErrors('server_ids');
    $this->post($url, ['server_ids' => [$foreign->id]])->assertSessionHasErrors('server_ids');
    $this->post($url, ['server_ids' => [$deleting->id]])->assertSessionHasErrors('server_ids');
    $this->post($url, ['server_ids' => [$this->servers[0]->id], 'env' => [['name' => '1BAD', 'value' => 'x']]])->assertSessionHasErrors('env.0.name');
    $this->post($url, ['server_ids' => [$this->servers[0]->id], 'env' => [['name' => 'A-B', 'value' => 'x']]])->assertSessionHasErrors('env.0.name');
    $this->post($url, ['server_ids' => [$this->servers[0]->id], 'timeout' => 99999])->assertSessionHasErrors('timeout');

    config(['recipes.max_servers' => 2]);
    $this->post($url, ['server_ids' => $this->servers->pluck('id')->all()])->assertSessionHasErrors('server_ids');

    expect(Run::query()->count())->toBe(0);
    $this->agents->assertNothingDispatched();
});

it('lets developers manage but not run recipes, and viewers only read history', function () {
    $run = recipes_run([$this->servers[0]->id]);

    actingAsMember(Role::Developer, $this->organization);
    $this->post("/recipes/{$this->recipe->id}/runs", ['server_ids' => [$this->servers[0]->id]])->assertForbidden();
    $this->post('/recipes/builtin/disk-usage/runs', ['server_ids' => [$this->servers[0]->id]])->assertForbidden();
    $this->get("/recipes/{$this->recipe->id}/run")->assertForbidden();
    $this->put("/recipes/{$this->recipe->id}", ['name' => 'Uptime', 'script' => 'uptime -p', 'user' => 'root'])->assertSessionHasNoErrors();

    actingAsMember(Role::Viewer, $this->organization);
    $this->get('/recipes/runs')->assertOk()->assertInertia(fn ($page) => $page->component('Recipes/History', false)->has('runs.data', 1));
    $this->get("/recipes/runs/{$run->id}")->assertOk()->assertInertia(fn ($page) => $page
        ->component('Recipes/RunShow', false)
        ->has('targets', 1)
        ->where('can.run', false)
        ->missing('run.env'));
    $this->getJson("/recipes/runs/{$run->id}/status")->assertOk()->assertJsonPath('data.status', 'pending');

    actingAsMember(Role::Owner);
    $this->get("/recipes/runs/{$run->id}")->assertNotFound();
    $this->getJson("/recipes/runs/{$run->id}/status")->assertNotFound();
    $this->post("/recipes/{$this->recipe->id}/runs", ['server_ids' => [$this->servers[0]->id]])->assertNotFound();

    expect(Run::query()->count())->toBe(1);
});

it('filters run history', function () {
    recipes_run([$this->servers[0]->id]);
    $second = recipes_run([$this->servers[1]->id]);
    $this->agents->succeed($this->agents->last('system.exec')['handle']);

    $this->get('/recipes/runs?status=succeeded')->assertInertia(fn ($page) => $page->has('runs.data', 1)->where('runs.data.0.id', $second->id));
    $this->get("/recipes/runs?server={$this->servers[0]->id}")->assertInertia(fn ($page) => $page->has('runs.data', 1));
    $this->get("/recipes/runs?recipe={$this->recipe->id}")->assertInertia(fn ($page) => $page->has('runs.data', 2));
});

it('keeps run history when a server is deleted', function () {
    $run = recipes_run([$this->servers[0]->id]);
    $this->servers[0]->delete();

    $this->get("/recipes/runs/{$run->id}")->assertOk()->assertInertia(fn ($page) => $page->where('targets.0.server_name', 'web-1'));
});

it('authorizes the run broadcast channel per organization', function () {
    $run = recipes_run([$this->servers[0]->id]);
    $channel = app(RunChannel::class);

    [$viewer] = memberOf($this->organization, Role::Viewer);
    [$outsider] = memberOf();

    expect($channel->join($viewer, $run->id))->toBeTrue()
        ->and($channel->join($outsider, $run->id))->toBeFalse()
        ->and($channel->join($viewer, 'missing'))->toBeFalse();
});
