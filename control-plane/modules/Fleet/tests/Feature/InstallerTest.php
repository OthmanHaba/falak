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

    // Preflight, a previous install, stopping the old agent before enrolling, and the connection check.
    expect($script)->toContain("KILN_AGENT_API='https://panel.kiln.test/agent/v1'")
        ->toContain('"$KILN_AGENT_API/ping"')
        ->toContain('ubuntu:26.04) say "note: on Ubuntu 26.04 PHP comes from Ubuntu\'s own archive, which has only PHP 8.5"')
        ->toContain('if [ -f /etc/kiln/agent.json ]; then')
        ->toContain('"$BIN" check --wait 60s')
        ->toContain('journalctl -u kiln-agent');
    expect(strpos($script, 'systemctl stop kiln-agent'))->toBeLessThan(strpos($script, '"$BIN" enroll'));
    expect(strpos($script, '"$BIN" install'))->toBeLessThan(strpos($script, '"$BIN" check'));
    expect($script)->not->toContain("\r")->not->toContain(chr(1));

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

it('uses a separate agents host for the agent API check', function () {
    config(['fleet.api_url' => 'https://agents.kiln.test/agent/v1']);
    $install = app(Enrollment::class)->issueInstallToken($this->organization->id, null);

    expect($this->get("/install/{$install->token}")->getContent())->toContain("KILN_AGENT_API='https://agents.kiln.test/agent/v1'");
});

/**
 * Runs the installer with stubbed system tools until it stops: preflight runs before anything changes on the
 * machine, and the stubbed download fails, so nothing is written outside the sandbox.
 *
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string, 2: string, 3: string} [exit code, stdout, stderr, stub calls]
 */
function run_installer(string $script, array $env): array
{
    $dir = sys_get_temp_dir().'/kiln-installer-'.bin2hex(random_bytes(4));
    mkdir($dir.'/bin', 0o755, true);
    $stubs = [
        'id' => 'echo 0',
        'uname' => 'case "$1" in -s) echo Linux ;; -m) echo x86_64 ;; esac',
        'systemctl' => 'echo "systemctl $*" >> "$STUB_LOG"; exit 0',
        'apt-get' => 'exit 0',
        // Panel HEAD → headers with its Date; agent API probe → 401 (or down); the download fails.
        'curl' => <<<'STUB'
echo "curl $*" >> "$STUB_LOG"
case "$*" in
    *" -I "*) printf 'HTTP/2 302\r\ndate: Fri, 02 Oct 2026 12:00:00 GMT\r\nlocation: /login\r\n\r\n' ;;
    */ping*) if [ -n "${API_DOWN:-}" ]; then echo "curl: (7) Failed to connect to agents.kiln.test port 443" >&2; exit 7; fi; printf 401 ;;
    *) exit 22 ;;
esac
STUB,
        // GNU date semantics for the two calls the clock check makes.
        'date' => 'case "$2" in -d) echo "$PANEL_TS" ;; +%s) echo "$LOCAL_TS" ;; *) echo "2026-10-02 12:00:00" ;; esac',
    ];
    foreach ($stubs as $name => $body) {
        file_put_contents("{$dir}/bin/{$name}", "#!/bin/sh\n{$body}\n");
        chmod("{$dir}/bin/{$name}", 0o755);
    }

    $process = new Process(['sh', '-s'], $dir, ['PATH' => "{$dir}/bin:/usr/bin:/bin", 'STUB_LOG' => "{$dir}/calls", ...$env]);
    $process->setInput($script);
    $process->run();
    $calls = (string) @file_get_contents("{$dir}/calls");
    array_map('unlink', [...(glob("{$dir}/bin/*") ?: []), ...(glob("{$dir}/calls") ?: [])]);
    rmdir("{$dir}/bin");
    rmdir($dir);

    return [(int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput(), $calls];
}

it('stops before changing anything when the clock is more than five minutes off', function () {
    $install = app(Enrollment::class)->issueInstallToken($this->organization->id, null);
    $script = $this->get("/install/{$install->token}")->getContent();

    [$code, , $stderr, $calls] = run_installer($script, ['PANEL_TS' => '1790000000', 'LOCAL_TS' => '1790000600']);

    expect($code)->toBe(1)
        ->and($stderr)->toContain("this machine's clock is 600s off the panel's")->toContain('timedatectl set-ntp true')
        ->and($calls)->not->toContain('curl -fsSL')->not->toContain('systemctl stop');
})->skip(PHP_OS_FAMILY === 'Windows');

it('fails clearly when the agent API cannot be reached', function () {
    config(['fleet.api_url' => 'https://agents.kiln.test/agent/v1']);
    $install = app(Enrollment::class)->issueInstallToken($this->organization->id, null);
    $script = $this->get("/install/{$install->token}")->getContent();

    [$code, , $stderr] = run_installer($script, ['PANEL_TS' => '1790000000', 'LOCAL_TS' => '1790000000', 'API_DOWN' => '1']);

    expect($code)->toBe(1)->and($stderr)->toContain('cannot reach the agent API at https://agents.kiln.test/agent/v1: curl: (7) Failed to connect');
})->skip(PHP_OS_FAMILY === 'Windows');

it('passes preflight with a small clock difference and goes on to the download', function () {
    $install = app(Enrollment::class)->issueInstallToken($this->organization->id, null);
    $script = $this->get("/install/{$install->token}")->getContent();

    [$code, $stdout, $stderr, $calls] = run_installer($script, ['PANEL_TS' => '1790000000', 'LOCAL_TS' => '1790000090']);

    expect($code)->toBe(1)
        ->and($stdout)->toContain('kiln: OS: ')->toContain('downloading kiln-agent (amd64)')
        ->and($stderr)->toContain("clock is 90s off the panel's; enable time sync")->toContain('download failed')
        ->and($calls)->toContain('https://panel.kiln.test/agent/v1/ping')->not->toContain('systemctl stop');
})->skip(PHP_OS_FAMILY === 'Windows');

it('serves published agent binaries by architecture', function () {
    file_put_contents($this->binaries.'/kiln-agent-linux-arm64', 'arm-bits');

    $this->get('/install/agent/linux-arm64')->assertOk()->assertHeader('X-Checksum-Sha256', hash('sha256', 'arm-bits'));
    $this->get('/install/agent/linux-amd64')->assertNotFound();
    $this->get('/install/agent/linux-mips')->assertNotFound();
});
