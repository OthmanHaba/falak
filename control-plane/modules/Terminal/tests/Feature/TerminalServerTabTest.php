<?php

use Kiln\Identity\Contracts\Role;
use Kiln\Servers\Contracts\ServerStatus;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    FakeAgentGateway::install();
    [$this->user, $this->organization] = actingAsMember(Role::Owner);
    $this->server = terminal_server($this->organization, ['status' => ServerStatus::Active]);
});

it('renders the terminal tab with the server header, sessions and recordings of that server', function () {
    $this->get("/servers/{$this->server->id}/terminal")->assertOk()->assertInertia(fn ($page) => $page
        ->component('Terminal/Server', false)
        ->where('server.id', $this->server->id)
        ->has('sessions', 0)
        ->has('recordings', 0)
        ->where('can.open', true)
        ->where('serverActive', true));
});

it('does not offer a shell on inactive servers', function () {
    $this->server->forceFill(['status' => ServerStatus::Provisioning])->save();

    $this->get("/servers/{$this->server->id}/terminal")->assertInertia(fn ($page) => $page->where('can.open', false));
});

it('hides the terminal tab of other organizations', function () {
    [$stranger] = memberOf();

    $this->actingAs($stranger)->get("/servers/{$this->server->id}/terminal")->assertNotFound();
});
