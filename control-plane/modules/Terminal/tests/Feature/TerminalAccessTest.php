<?php

use Illuminate\Support\Facades\Event;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\AuditEntry;
use Falak\Terminal\Domain\Models\TerminalFrame;
use Falak\Terminal\Events\TerminalOutput;
use Falak\Terminal\Events\TerminalSessionUpdated;
use Falak\Terminal\Http\Channels\TerminalSessionChannel;
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

const TERMINAL_STREAM = '0b7f5e0c-1d2a-4c3b-9e8f-7a6b5c4d3e2f';

function terminal_input(string $sessionId, string $bytes, int $seq = 0, string $stream = TERMINAL_STREAM)
{
    return test()->postJson("/terminal/sessions/{$sessionId}/input", ['data' => base64_encode($bytes), 'seq' => $seq, 'stream' => $stream]);
}

it('forwards keystrokes idempotently per sequence number', function () {
    terminal_input($this->session->id, "ls -la\r", 0)->assertNoContent();
    terminal_input($this->session->id, "ls -la\r", 0)->assertNoContent(); // client retry
    terminal_input($this->session->id, "\x03", 1)->assertNoContent();

    $inputs = $this->agents->dispatched('terminal.input');
    expect($inputs)->toHaveCount(2)
        ->and($inputs[0]['payload'])->toBe(['session_id' => $this->session->id, 'data' => base64_encode("ls -la\r")])
        ->and($inputs[0]['handle']->idempotencyKey)->toBe("terminal.input:{$this->session->id}:{$this->owner->id}:".TERMINAL_STREAM.':0')
        ->and($inputs[0]['timeout'])->toBe(30)
        ->and($inputs[1]['handle']->idempotencyKey)->toBe("terminal.input:{$this->session->id}:{$this->owner->id}:".TERMINAL_STREAM.':1');
});

it('does not collide input keys across page reloads (new stream, seq restarts)', function () {
    terminal_input($this->session->id, 'a', 0)->assertNoContent();
    $this->agents->succeed($this->agents->last('terminal.input')['handle'], ['bytes' => 1]);

    terminal_input($this->session->id, 'b', 0, 'aa1f5e0c-1d2a-4c3b-9e8f-7a6b5c4d3e2f')->assertNoContent();

    $keys = array_map(fn ($c) => $c['handle']->idempotencyKey, $this->agents->dispatched('terminal.input'));
    expect($keys)->toHaveCount(2)->and($keys[0])->not->toBe($keys[1]);

    test()->postJson("/terminal/sessions/{$this->session->id}/input", ['data' => base64_encode('x'), 'seq' => 0])->assertUnprocessable();
});

it('validates input batches', function () {
    $this->postJson("/terminal/sessions/{$this->session->id}/input", ['data' => '***not base64***', 'seq' => 0, 'stream' => TERMINAL_STREAM])->assertUnprocessable();
    $this->postJson("/terminal/sessions/{$this->session->id}/input", ['data' => base64_encode('x'), 'seq' => -1, 'stream' => TERMINAL_STREAM])->assertUnprocessable();
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

it('rotates the live channel on unshare so existing watchers are cut off', function () {
    Event::fake([TerminalSessionUpdated::class]);
    [$admin] = memberOf($this->organization, Role::Admin);
    $channel = app(TerminalSessionChannel::class);

    $this->patch("/terminal/sessions/{$this->session->id}/share", ['shared' => true])->assertRedirect();
    expect($channel->join($admin, $this->session->id, '0'))->toBeArray();

    $this->patch("/terminal/sessions/{$this->session->id}/share", ['shared' => false])->assertRedirect();

    expect($this->session->refresh()->channel_epoch)->toBe(1)
        // Old epoch can no longer be joined by anyone; the new one only by the owner.
        ->and($channel->join($this->owner, $this->session->id, '0'))->toBeFalse()
        ->and($channel->join($this->owner, $this->session->id, '1'))->toBeArray()
        ->and($channel->join($admin, $this->session->id, '1'))->toBeFalse();

    Event::assertDispatched(TerminalSessionUpdated::class, fn ($e) => $e->broadcastOn()->name === "presence-terminal.sessions.{$this->session->id}.0" && $e->broadcastWith()['channel_epoch'] === 1 && ! $e->shared);
    Event::assertDispatched(TerminalSessionUpdated::class, fn ($e) => $e->broadcastOn()->name === "presence-terminal.sessions.{$this->session->id}.1");

    // New output only goes to the new channel.
    Event::fake([TerminalOutput::class]);
    $this->agents->emit($this->handle, base64_encode('secret'));
    Event::assertDispatched(TerminalOutput::class, fn ($e) => $e->broadcastOn()->name === "presence-terminal.sessions.{$this->session->id}.1");
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
