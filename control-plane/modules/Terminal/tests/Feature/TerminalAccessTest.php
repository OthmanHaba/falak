<?php

use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Identity\Contracts\Role;
use Kiln\Identity\Domain\Models\AuditEntry;
use Kiln\Terminal\Domain\Models\TerminalFrame;
use Kiln\Terminal\Http\Channels\TerminalSessionChannel;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    $this->agents = FakeAgentGateway::install();
    [$this->owner, $this->organization] = actingAsMember(Role::Admin);
    $this->server = terminal_server($this->organization);
    $this->session = terminal_open($this->server);
    $this->handle = terminal_open_command($this->agents)['handle'];
    $this->agents->started($this->handle);
    $this->session->refresh();
});

function terminal_input(string $sessionId, string $bytes, int $seq = 0)
{
    return test()->postJson("/terminal/sessions/{$sessionId}/input", ['data' => base64_encode($bytes), 'seq' => $seq]);
}

it('forwards keystrokes idempotently per sequence number', function () {
    terminal_input($this->session->id, "ls -la\r", 0)->assertNoContent();
    terminal_input($this->session->id, "ls -la\r", 0)->assertNoContent(); // client retry
    terminal_input($this->session->id, "\x03", 1)->assertNoContent();

    $inputs = $this->agents->dispatched('terminal.input');
    expect($inputs)->toHaveCount(2)
        ->and($inputs[0]['payload'])->toBe(['session_id' => $this->session->id, 'data' => base64_encode("ls -la\r")])
        ->and($inputs[0]['handle']->idempotencyKey)->toBe("terminal.input:{$this->session->id}:0")
        ->and($inputs[0]['timeout'])->toBe(30)
        ->and($inputs[1]['handle']->idempotencyKey)->toBe("terminal.input:{$this->session->id}:1");
});

it('validates input batches', function () {
    $this->postJson("/terminal/sessions/{$this->session->id}/input", ['data' => '***not base64***', 'seq' => 0])->assertUnprocessable();
    $this->postJson("/terminal/sessions/{$this->session->id}/input", ['data' => base64_encode('x'), 'seq' => -1])->assertUnprocessable();
    $this->postJson("/terminal/sessions/{$this->session->id}/input", ['seq' => 0])->assertUnprocessable();

    config(['terminal.input_max_bytes' => 10]);
    terminal_input($this->session->id, str_repeat('a', 11))->assertStatus(413);

    $this->agents->assertNothingDispatched('terminal.input');
});

it('rejects input to closed sessions and disconnected agents', function () {
    $this->agents->unavailable($this->server->id);
    terminal_input($this->session->id, 'x')->assertStatus(503);

    $this->agents->available($this->server->id);
    $this->agents->succeed($this->handle, ['reason' => 'exited', 'exit_code' => 0]);
    terminal_input($this->session->id, 'x', 1)->assertStatus(409);
});

it('resizes the PTY and records the resize', function () {
    $this->postJson("/terminal/sessions/{$this->session->id}/resize", ['cols' => 132, 'rows' => 43])->assertNoContent();

    expect($this->agents->last('terminal.resize')['payload'])->toBe(['session_id' => $this->session->id, 'cols' => 132, 'rows' => 43])
        ->and($this->session->refresh()->cols)->toBe(132)
        ->and(TerminalFrame::query()->where('kind', 'r')->value('data'))->toBe('132x43');

    $this->postJson("/terminal/sessions/{$this->session->id}/resize", ['cols' => 0, 'rows' => 43])->assertUnprocessable();
});

it('lets attachers watch shared sessions but only controllers type', function () {
    [$watcher] = memberOf($this->organization, Role::Developer);
    [$admin] = memberOf($this->organization, Role::Admin);
    $channel = app(TerminalSessionChannel::class);

    // Not shared yet: nobody but the owner.
    expect($channel->join($admin, $this->session->id))->toBeFalse()
        ->and($channel->join($this->owner, $this->session->id))->toMatchArray(['id' => $this->owner->id, 'can_type' => true]);
    $this->actingAs($admin)->get("/terminal/sessions/{$this->session->id}")->assertForbidden();

    $this->actingAs($this->owner)->patch("/terminal/sessions/{$this->session->id}/share", ['shared' => true])->assertRedirect();
    expect($this->session->refresh()->shared)->toBeTrue()
        ->and(AuditEntry::query()->where('action', 'terminal.session_shared')->exists())->toBeTrue();

    // Admin holds terminal.attach + terminal.control.
    expect($channel->join($admin, $this->session->id))->toMatchArray(['id' => $admin->id, 'can_type' => true]);
    $this->actingAs($admin)->get("/terminal/sessions/{$this->session->id}")->assertOk();
    expect(AuditEntry::query()->where('action', 'terminal.session_attached')->where('actor_id', $admin->id)->exists())->toBeTrue();
    $this->actingAs($admin);
    terminal_input($this->session->id, 'whoami', 7)->assertNoContent();

    // Developers have no terminal permissions at all by default.
    expect($channel->join($watcher, $this->session->id))->toBeFalse();
    $this->actingAs($watcher)->get("/terminal/sessions/{$this->session->id}")->assertNotFound();
});

it('lets members with attach but without control watch only', function () {
    [$watcher] = memberOf($this->organization, Role::Developer);
    $this->session->forceFill(['shared' => true])->save();

    // Grant terminal.attach to this developer only.
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
    $watcher->givePermissionTo('terminal.attach');
    app(OrganizationAccess::class)->flush();

    expect(app(TerminalSessionChannel::class)->join($watcher->refresh(), $this->session->id))->toMatchArray(['can_type' => false]);

    $this->actingAs($watcher);
    $this->get("/terminal/sessions/{$this->session->id}")->assertOk()->assertInertia(fn ($page) => $page->where('can.type', false)->where('isOwner', false));
    terminal_input($this->session->id, 'rm -rf /')->assertForbidden();
    $this->postJson("/terminal/sessions/{$this->session->id}/resize", ['cols' => 10, 'rows' => 10])->assertForbidden();
    $this->delete("/terminal/sessions/{$this->session->id}")->assertForbidden();
    $this->patch("/terminal/sessions/{$this->session->id}/share", ['shared' => false])->assertForbidden();
});

it('hides sessions from other organizations', function () {
    [$stranger] = actingAsMember(Role::Owner);

    expect(app(TerminalSessionChannel::class)->join($stranger, $this->session->id))->toBeFalse();
    $this->get("/terminal/sessions/{$this->session->id}")->assertNotFound();
    terminal_input($this->session->id, 'x')->assertNotFound();
    $this->get("/terminal/sessions/{$this->session->id}/recording.cast")->assertNotFound();
});

it('only lets the owner share, and admins with control close others\' sessions', function () {
    [$admin] = memberOf($this->organization, Role::Admin);

    $this->actingAs($admin)->patch("/terminal/sessions/{$this->session->id}/share", ['shared' => true])->assertForbidden();
    $this->actingAs($admin)->delete("/terminal/sessions/{$this->session->id}")->assertRedirect();

    expect($this->session->refresh()->close_reason)->toBe('closed');
});

it('serves catch-up frames after a cursor', function () {
    $this->agents->emit($this->handle, [base64_encode('one'), base64_encode('two'), base64_encode('three')]);
    $first = TerminalFrame::query()->orderBy('id')->firstOrFail();

    $this->getJson("/terminal/sessions/{$this->session->id}/frames?after={$first->id}")
        ->assertOk()
        ->assertJsonPath('frames.0.data', base64_encode('two'))
        ->assertJsonPath('frames.0.seq', 2)
        ->assertJsonCount(2, 'frames')
        ->assertJsonPath('more', false)
        ->assertJsonPath('status', 'open');
});
