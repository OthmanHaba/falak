<?php

use Falak\Identity\Contracts\Role;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Models\SiteCommand;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->agents = sites_fake_agents();
    sites_fake_source_control();
    $this->a = sites_server($this->organization->id, ['name' => 'web-1']);
    $this->b = sites_server($this->organization->id, ['name' => 'web-2']);
    $this->post('/sites', sites_input([$this->a->id, $this->b->id], ['isolated' => true]));
    $this->site = Site::query()->firstOrFail();
});

it('edits the deploy script', function () {
    $this->put("/sites/{$this->site->id}/deploy-script", ['script' => "\$FALAK_FETCH\r\n\$FALAK_ACTIVATE\r\n\r\n"])->assertSessionHasNoErrors();

    expect($this->site->refresh()->deploy_script)->toBe("\$FALAK_FETCH\n\$FALAK_ACTIVATE\n");

    [$viewer] = memberOf($this->organization, Role::Viewer);
    $this->actingAs($viewer)->put("/sites/{$this->site->id}/deploy-script", ['script' => 'x'])->assertForbidden();
});

it('runs a command on the leader by default as the site user and records the outcome', function () {
    $this->post("/sites/{$this->site->id}/commands", ['command' => 'php8.4 artisan migrate:status'])->assertSessionHasNoErrors();

    $exec = $this->agents->last('system.exec');
    expect($exec['server_id'])->toBe($this->a->id)
        ->and($exec['payload']['user'])->toBe('shop')
        ->and($exec['payload']['script'])->toContain('php8.4 artisan migrate:status')
        ->and($exec['payload']['env'])->toMatchArray(['FALAK_SITE' => 'shop', 'FALAK_ROLE' => 'leader', 'FALAK_PHP' => 'php8.4']);

    $command = SiteCommand::query()->firstOrFail();
    expect($command->status)->toBe('queued')->and($command->command_id)->toBe($exec['id']);

    sites_finish($exec, success: false, error: 'exit status 1', exitCode: 1);

    expect($command->refresh()->status)->toBe('failed')->and($command->exit_code)->toBe(1)->and($command->finished_at)->not->toBeNull();

    $this->post("/sites/{$this->site->id}/commands", ['command' => 'ls', 'server_id' => $this->b->id])->assertSessionHasNoErrors();
    sites_finish($this->agents->last('system.exec'));

    expect($this->agents->last('system.exec')['payload']['env']['FALAK_IS_LEADER'])->toBe('0')
        ->and(SiteCommand::query()->latest('id')->first()->status)->toBe('succeeded');
});

it('validates the target server and permission', function () {
    $other = sites_server($this->organization->id);
    $this->post("/sites/{$this->site->id}/commands", ['command' => 'ls', 'server_id' => $other->id])->assertSessionHasErrors('server_id');
    $this->post("/sites/{$this->site->id}/commands", ['command' => ''])->assertSessionHasErrors('command');

    [$viewer] = memberOf($this->organization, Role::Viewer);
    $this->actingAs($viewer)->post("/sites/{$this->site->id}/commands", ['command' => 'ls'])->assertForbidden();
});

it('keeps a bounded command history', function () {
    config(['sites.command_history' => 3]);

    foreach (range(1, 5) as $i) {
        $this->post("/sites/{$this->site->id}/commands", ['command' => "echo {$i}"]);
    }

    expect(SiteCommand::query()->count())->toBe(3);
});

it('exposes deploy variables with exposed environment keys through the directory', function () {
    $this->put("/sites/{$this->site->id}/environment", ['content' => "APP_ENV=production\nSECRET=x", 'exposed' => ['APP_ENV']]);

    $vars = app(SiteDirectory::class)->deployVariables($this->site->id, $this->b->id, ['FALAK_COMMIT' => 'abc', 'NOT_FALAK' => 'x']);

    expect($vars)->toMatchArray([
        'APP_ENV' => 'production',
        'FALAK_SITE' => 'shop',
        'FALAK_SITE_ROOT' => '/srv/falak/sites/shop',
        'FALAK_SHARED_DIR' => '/srv/falak/sites/shop/shared',
        'FALAK_ROLE' => 'member',
        'FALAK_IS_LEADER' => '0',
        'FALAK_PHP' => 'php8.4',
        'FALAK_COMMIT' => 'abc',
    ])->and($vars)->not->toHaveKey('SECRET')->not->toHaveKey('NOT_FALAK');
});
