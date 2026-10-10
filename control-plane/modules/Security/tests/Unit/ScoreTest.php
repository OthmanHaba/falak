<?php

use Falak\Security\Domain\FixCatalogue;
use Falak\Security\Domain\Score;

it('takes the weighted failures and warnings off 100', function (array $checks, int $score, bool $ready) {
    $result = Score::of(array_map(fn (string $c) => array_combine(['status', 'severity'], explode(':', $c)), $checks));

    expect($result['score'])->toBe($score)->and($result['production_ready'])->toBe($ready);
})->with([
    'clean' => [['pass:high', 'info:info'], 100, true],
    'one critical' => [['fail:critical'], 70, false],
    'one high' => [['fail:high'], 85, false],
    'medium and low fail' => [['fail:medium', 'fail:low'], 93, true],
    'warnings cost about half and never block' => [['warn:critical', 'warn:high', 'warn:medium', 'warn:low'], 75, true],
    'never below zero' => [array_fill(0, 5, 'fail:critical'), 0, false],
    'info status is free' => [['info:critical'], 100, true],
]);

it('counts statuses and the severities that need attention', function () {
    expect(Score::of([['status' => 'fail', 'severity' => 'high'], ['status' => 'warn', 'severity' => 'high'], ['status' => 'pass', 'severity' => 'high']])['counts'])
        ->toMatchArray(['fail' => 1, 'warn' => 1, 'pass' => 1, 'info' => 0, 'high' => 2, 'critical' => 0]);
});

it('knows its fixes and parses port parameters strictly', function () {
    expect(FixCatalogue::find('ssh.harden'))->toMatchArray(['disruptive' => true, 'agent' => true])
        ->and(FixCatalogue::find('kernel.sysctl')['disruptive'])->toBeFalse()
        ->and(FixCatalogue::find('firewall.close_port:tcp:8080'))->toMatchArray(['base' => 'firewall.close_port', 'params' => ['tcp', '8080'], 'agent' => false])
        ->and(FixCatalogue::find('firewall.close_port:tcp:0'))->toBeNull()
        ->and(FixCatalogue::find('firewall.close_port:icmp:1'))->toBeNull()
        ->and(FixCatalogue::find('firewall.close_port:tcp:80:x'))->toBeNull()
        ->and(FixCatalogue::find('kernel.sysctl:x'))->toBeNull()
        ->and(FixCatalogue::find('system.exec'))->toBeNull();
});

it('mirrors the agent allowlist', function () {
    $agent = (string) file_get_contents(dirname(__DIR__, 5).'/agent/internal/security/fixes.go');
    preg_match_all('/\{ID: "([a-z0-9_.]+)"/', $agent, $m);

    expect(array_keys(FixCatalogue::FIXES))->toEqualCanonicalizing($m[1]);

    foreach (FixCatalogue::FIXES as $id => $fix) {
        preg_match('/\{ID: "'.preg_quote($id, '/').'"([^}]*)\}/', $agent, $line);
        expect(str_contains($line[1], 'Disruptive: true'))->toBe($fix['disruptive'], "{$id} disruptive")
            ->and(str_contains($line[1], 'ControlPlane: true'))->toBe(! $fix['agent'], "{$id} agent");
    }
});
