<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Falak\Identity\Contracts\Role;
use Falak\Sites\Contracts\Data\SitePlacement;
use Falak\Sites\Contracts\SiteFactory;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Events\SiteCreated;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->agents = sites_fake_agents();
    sites_fake_source_control();
    config(['sites.test_domain' => 'falak.test']);
    $this->server = sites_server($this->organization->id, ['name' => 'web-1']);
});

it('creates sites with the form rules and passes the placement on SiteCreated', function () {
    Event::fake([SiteCreated::class]);
    $placement = new SitePlacement('p', 'e', 10, 20, 'web');

    $created = app(SiteFactory::class)->create($this->organization->id, $this->user->id, sites_input([$this->server->id]), $placement);

    expect($created->site->name)->toBe('Shop')
        ->and($created->site->targets)->toHaveCount(1)
        ->and($created->warnings)->toBe([]);
    Event::assertDispatched(SiteCreated::class, fn ($e) => $e->siteId === $created->site->id && $e->placement === $placement);

    expect(fn () => app(SiteFactory::class)->create($this->organization->id, $this->user->id, sites_input([$this->server->id])))
        ->toThrow(ValidationException::class, 'The name has already been taken.');
});

it('duplicates a site without servers, copying configuration and variables', function () {
    $source = app(SiteFactory::class)->create($this->organization->id, $this->user->id, sites_input([$this->server->id], ['push_to_deploy' => false]))->site;
    $model = Site::query()->findOrFail($source->id);
    $model->forceFill(['deploy_script' => "echo hi\n", 'laravel' => ['horizon' => true]])->save();
    $variables = $model->latestEnvironment->variables;

    $copy = app(SiteFactory::class)->duplicate($source->id, ['name_suffix' => 'staging'])->site;
    $copyModel = Site::query()->with('latestEnvironment')->findOrFail($copy->id);

    expect($copy->name)->toBe('Shop-staging')
        ->and($copy->targets)->toBe([])
        ->and($copy->deployScript)->toBe("echo hi\n")
        ->and($copy->laravel->horizon)->toBeTrue()
        ->and($copyModel->latestEnvironment->variables['APP_KEY'])->toBe($variables['APP_KEY'])
        ->and($copyModel->latestEnvironment->variables['APP_URL'])->toBe("https://{$copy->slug}.falak.test");

    expect(app(SiteFactory::class)->duplicate($source->id, ['name_suffix' => 'staging'])->site->name)->toBe('Shop-staging-2');
    expect(fn () => app(SiteFactory::class)->duplicate($source->id, ['name' => 'Shop']))->toThrow(ValidationException::class);
});
