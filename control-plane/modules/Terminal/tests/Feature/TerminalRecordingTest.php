<?php

use Kiln\Identity\Contracts\Role;
use Kiln\Identity\Domain\Models\AuditEntry;
use Kiln\Terminal\Domain\Models\TerminalFrame;
use Kiln\Terminal\Infrastructure\AsciicastWriter;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    $this->agents = FakeAgentGateway::install();
    [$this->owner, $this->organization] = actingAsMember(Role::Admin);
    $this->server = terminal_server($this->organization, ['name' => 'db-1']);
    $this->session = terminal_open($this->server, ['cols' => 100, 'rows' => 30]);
    $this->handle = terminal_open_command($this->agents)['handle'];
});

/**
 * @return list<array<int, mixed>>
 */
function terminal_cast_lines(string $cast): array
{
    return array_map(fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), array_values(array_filter(explode("\n", $cast))));
}

it('renders an asciicast v2 recording with header, output and resize events', function () {
    $start = now()->startOfSecond();
    $this->agents->emit($this->handle, base64_encode("\$ ls\r\n"), at: $start->format('Y-m-d\TH:i:s.v\Z'));
    $this->agents->emit($this->handle, base64_encode("a.txt\r\n"), at: $start->copy()->addMilliseconds(1500)->format('Y-m-d\TH:i:s.v\Z'));

    $this->travelTo($start->copy()->addSeconds(2));
    $this->postJson("/terminal/sessions/{$this->session->id}/resize", ['cols' => 120, 'rows' => 40])->assertNoContent();

    $response = $this->get("/terminal/sessions/{$this->session->id}/recording.cast")->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/x-asciicast');

    $lines = terminal_cast_lines($response->streamedContent());

    expect($lines[0])->toBe([
        'version' => 2,
        'width' => 100,
        'height' => 30,
        'timestamp' => $start->getTimestamp(),
        'env' => ['TERM' => 'xterm-256color', 'SHELL' => '/bin/bash'],
        'title' => 'root@db-1',
    ])
        ->and($lines[1])->toBe([0.0, 'o', "\$ ls\r\n"])
        ->and($lines[2])->toBe([1.5, 'o', "a.txt\r\n"])
        ->and($lines[3][1])->toBe('r')
        ->and($lines[3][2])->toBe('120x40')
        ->and($lines[3][0])->toBeGreaterThanOrEqual(1.5);
});

it('keeps multibyte characters intact across frame boundaries and scrubs invalid bytes', function () {
    $euro = "\xE2\x82\xAC"; // €
    $emoji = "\xF0\x9F\x94\xA5"; // 🔥
    $at = now()->toIso8601ZuluString();

    $this->agents->emit($this->handle, [
        base64_encode('price: '.substr($euro, 0, 2)),
        base64_encode(substr($euro, 2).' '.substr($emoji, 0, 1)),
        base64_encode(substr($emoji, 1)."\xFF!"),
        base64_encode("tail\xE2\x82"),
    ], at: $at);

    $events = terminal_cast_lines(app(AsciicastWriter::class)->write($this->session->refresh()));
    array_shift($events);
    $text = implode('', array_column($events, 2));

    expect($text)->toBe("price: € 🔥\u{FFFD}!tail\u{FFFD}")
        ->and(collect($events)->every(fn ($e) => mb_check_encoding($e[2], 'UTF-8')))->toBeTrue()
        ->and($events[0][2])->toBe('price: ');
});

it('never lets event times go backwards', function () {
    $this->agents->started($this->handle);
    $session = $this->session->refresh();

    $frames = collect([
        new TerminalFrame(['kind' => 'o', 'offset_ms' => 2000, 'data' => base64_encode('b')]),
        new TerminalFrame(['kind' => 'o', 'offset_ms' => 1000, 'data' => base64_encode('c')]),
        new TerminalFrame(['kind' => 'r', 'offset_ms' => 500, 'data' => '80x24']),
    ]);

    $times = array_map(fn ($e) => $e[0], iterator_to_array(app(AsciicastWriter::class)->events($frames), false));

    expect($times)->toBe([2.0, 2.0, 2.0])
        ->and($session->offsetFor($session->started_at->copy()->subSecond()))->toBe(0);
});

it('shows the playback page to the owner and audits it', function () {
    $this->agents->succeed($this->handle, ['reason' => 'exited', 'exit_code' => 0]);

    $this->get("/terminal/sessions/{$this->session->id}/recording")->assertOk()->assertInertia(fn ($page) => $page
        ->component('Terminal/Playback', false)
        ->where('session.id', $this->session->id)
        ->where('castUrl', route('terminal.sessions.recording.cast', $this->session)));

    expect(AuditEntry::query()->where('action', 'terminal.recording_viewed')->where('subject_id', $this->session->id)->exists())->toBeTrue();
});

it('restricts replays of others\' sessions to terminal.recordings.view', function () {
    [$admin] = memberOf($this->organization, Role::Admin);
    [$developer] = memberOf($this->organization, Role::Developer);

    $this->actingAs($admin)->get("/terminal/sessions/{$this->session->id}/recording.cast")->assertOk();
    $this->actingAs($developer)->get("/terminal/sessions/{$this->session->id}/recording.cast")->assertNotFound();
});
