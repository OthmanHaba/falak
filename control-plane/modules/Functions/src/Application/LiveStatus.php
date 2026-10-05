<?php

namespace Falak\Functions\Application;

use Illuminate\Contracts\Cache\Repository as Cache;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\CommandStatus;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Sites\Contracts\SiteDirectory;
use Throwable;

/**
 * What a function runs right now (instances, requests in flight, cold starts), from the leader server's gateway
 * (fn.status). HTTP requests never wait for the agent: each poll returns the last answer and asks again when it
 * is older than a few seconds.
 */
final class LiveStatus
{
    private const FRESH_SECONDS = 5;

    private const PENDING_SECONDS = 30;

    public function __construct(
        private readonly AgentGateway $agents,
        private readonly SiteDirectory $sites,
        private readonly Cache $cache,
    ) {}

    /**
     * @return array{status: ?array<string, mixed>, at: ?string}
     */
    public function for(string $siteId, string $slug): array
    {
        $key = "functions:live:{$siteId}";
        /** @var array{command?: string, sent?: int, result?: ?array<string, mixed>, at?: int} $state */
        $state = (array) $this->cache->get($key, []);
        $now = time();

        if (isset($state['command'])) {
            try {
                $result = $this->agents->status($state['command']);

                if ($result->isFinished()) {
                    if ($result->status === CommandStatus::Succeeded) {
                        $state['result'] = $this->entry($result->result, $slug);
                        $state['at'] = $now;
                    }
                    unset($state['command'], $state['sent']);
                } elseif ($now - (int) ($state['sent'] ?? 0) > self::PENDING_SECONDS) {
                    unset($state['command'], $state['sent']);
                }
            } catch (Throwable) {
                unset($state['command'], $state['sent']);
            }
        }

        if (! isset($state['command']) && $now - (int) ($state['at'] ?? 0) >= self::FRESH_SECONDS && ($leader = $this->sites->leader($siteId)) !== null) {
            try {
                $state['command'] = $this->agents->dispatch($leader->serverId, 'fn.status', ['site' => $slug], 30)->id;
                $state['sent'] = $now;
            } catch (AgentUnavailable) {
                // Offline server: keep the last answer.
            }
        }

        $this->cache->put($key, $state, 600);

        return [
            'status' => $state['result'] ?? null,
            'at' => isset($state['at']) ? date(DATE_ATOM, (int) $state['at']) : null,
        ];
    }

    /**
     * @param  ?array<string, mixed>  $result  fn.status result
     * @return array<string, mixed>
     */
    private function entry(?array $result, string $slug): array
    {
        foreach ((array) ($result['functions'] ?? []) as $function) {
            if (is_array($function) && ($function['site'] ?? null) === $slug) {
                return $function;
            }
        }

        // Not registered on the server (not deployed yet, or removed).
        return ['site' => $slug, 'release' => null, 'running' => 0, 'starting' => 0, 'in_flight' => 0, 'cold_starts' => 0, 'requests' => 0, 'last_request_at' => null];
    }
}
