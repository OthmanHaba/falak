<?php

use Illuminate\Support\Str;
use Kiln\Fleet\Contracts\Enrollment;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    config(['app.url' => 'https://panel.kiln.test']);
    [, $this->organization] = memberOf();
    $this->binaries = sys_get_temp_dir().'/kiln-bin-'.bin2hex(random_bytes(4));
    mkdir($this->binaries);
    config(['fleet.agent.binaries_path' => $this->binaries]);
});

afterEach(function () {
    array_map('unlink', glob($this->binaries.'/*') ?: []);
    rmdir($this->binaries);
});

it('serves a valid POSIX sh installer for a usable token', function () {
    file_put_contents($this->binaries.'/kiln-agent-linux-amd64', 'fake-amd64-binary');
    $install = app(Enrollment::class)->issueInstallToken($this->organization->id, (string) Str::ulid());

    $response = $this->get("/install/{$install->token}")->assertOk()->assertHeader('Content-Type', 'text/x-shellscript; charset=utf-8');
    $script = $response->getContent();

    expect($script)->toStartWith("#!/bin/sh\n")
        ->toContain("KILN_PANEL_URL='https://panel.kiln.test'")
        ->toContain("KILN_TOKEN='{$install->token}'")
        ->toContain('URL="https://panel.kiln.test/install/agent/linux-${ARCH}"')
        ->toContain("amd64) SHA256='".hash('sha256', 'fake-amd64-binary')."'")
        ->toContain("arm64) SHA256=''")
        ->toContain('"$BIN" enroll --panel "$KILN_PANEL_URL" --token "$KILN_TOKEN"')
        ->toContain('"$BIN" install')
        ->not->toContain('ExecStart');

    $process = new Process(['sh', '-n']);
    $process->setInput($script);
    $process->run();
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    expect($response->headers->get('Set-Cookie'))->toBeNull();
});

it('uses an external download URL and pinned checksums when configured', function () {
    config([
        'fleet.agent.download_url' => 'https://releases.kiln.test/v1.2.3/kiln-agent-linux-{arch}',
        'fleet.agent.checksums' => ['arm64' => str_repeat('b', 64)],
    ]);
    $install = app(Enrollment::class)->issueInstallToken($this->organization->id, null);

    $script = $this->get("/install/{$install->token}")->assertOk()->getContent();

    expect($script)->toContain('URL="https://releases.kiln.test/v1.2.3/kiln-agent-linux-${ARCH}"')
        ->toContain("arm64) SHA256='".str_repeat('b', 64)."'");
});

it('returns a failing script for invalid or used tokens', function () {
    $this->get('/install/'.Str::random(48))->assertNotFound()->assertSee('invalid, expired or already used', false);

    $install = app(Enrollment::class)->issueInstallToken($this->organization->id, (string) Str::ulid());
    [$csr] = fleet_csr();
    $this->postJson('/agent/v1/enroll', ['token' => $install->token, 'csr_pem' => $csr, 'facts' => fleet_facts()])->assertCreated();

    $this->get("/install/{$install->token}")->assertNotFound();
});

it('quotes hostile values safely', function () {
    config(['fleet.panel_url' => "https://evil.test/'; rm -rf / #"]);
    $install = app(Enrollment::class)->issueInstallToken($this->organization->id, null);

    $script = $this->get("/install/{$install->token}")->getContent();

    expect($script)->toContain("KILN_PANEL_URL='https://evil.test/'\\''; rm -rf / #'");
    $process = new Process(['sh', '-n']);
    $process->setInput($script);
    $process->run();
    expect($process->getExitCode())->toBe(0);
});

it('serves published agent binaries by architecture', function () {
    file_put_contents($this->binaries.'/kiln-agent-linux-arm64', 'arm-bits');

    $this->get('/install/agent/linux-arm64')->assertOk()->assertHeader('X-Checksum-Sha256', hash('sha256', 'arm-bits'));
    $this->get('/install/agent/linux-amd64')->assertNotFound();
    $this->get('/install/agent/linux-mips')->assertNotFound();
});
