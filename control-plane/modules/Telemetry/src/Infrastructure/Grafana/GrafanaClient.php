<?php

namespace Kiln\Telemetry\Infrastructure\Grafana;

use Illuminate\Http\Client\PendingRequest;
use Kiln\Telemetry\Contracts\Exceptions\TelemetryUnavailable;
use Kiln\Telemetry\Infrastructure\HttpClient;

/**
 * Grafana HTTP API with a service-account token.
 */
final class GrafanaClient
{
    public function __construct(private readonly HttpClient $http) {}

    public static function fromConfig(): self
    {
        return new self(new HttpClient('Grafana', config('telemetry.grafana.url'), [], config('telemetry.grafana.token') ?: null));
    }

    public function configured(): bool
    {
        return $this->http->configured() && (string) config('telemetry.grafana.token') !== '';
    }

    public function health(): bool
    {
        try {
            return $this->http->get('/api/health')->successful();
        } catch (TelemetryUnavailable) {
            return false;
        }
    }

    /**
     * Create the folder, or rename it when it exists.
     *
     * @return array<string, mixed>
     */
    public function ensureFolder(string $uid, string $title): array
    {
        $existing = $this->http->get('/api/folders/'.rawurlencode($uid));

        if ($existing->successful()) {
            if (($existing->json('title') ?? null) === $title) {
                return (array) $existing->json();
            }

            return (array) $this->http->ensureSuccessful($this->http->send(fn (PendingRequest $r) => $r->put('/api/folders/'.rawurlencode($uid), [
                'title' => $title,
                'overwrite' => true,
            ])))->json();
        }

        if ($existing->status() !== 404) {
            $this->http->ensureSuccessful($existing);
        }

        return (array) $this->http->ensureSuccessful($this->http->send(fn (PendingRequest $r) => $r->post('/api/folders', [
            'uid' => $uid,
            'title' => $title,
        ])))->json();
    }

    /**
     * Create or update a datasource identified by its uid.
     *
     * @param  array<string, mixed>  $datasource  must contain "uid"
     * @return array<string, mixed>
     */
    public function upsertDatasource(array $datasource): array
    {
        $uid = (string) $datasource['uid'];
        $existing = $this->http->get('/api/datasources/uid/'.rawurlencode($uid));

        if ($existing->successful()) {
            $payload = [...$datasource, 'id' => $existing->json('id'), 'version' => $existing->json('version')];

            return (array) $this->http->ensureSuccessful($this->http->send(fn (PendingRequest $r) => $r->put('/api/datasources/uid/'.rawurlencode($uid), $payload)))->json();
        }

        if ($existing->status() !== 404) {
            $this->http->ensureSuccessful($existing);
        }

        return (array) $this->http->ensureSuccessful($this->http->send(fn (PendingRequest $r) => $r->post('/api/datasources', $datasource)))->json();
    }

    /**
     * @param  array<string, mixed>  $dashboard
     * @return array<string, mixed> {id, uid, url, status, version}
     */
    public function importDashboard(array $dashboard, string $folderUid, string $message = 'Provisioned by Kiln'): array
    {
        $dashboard['id'] = null;

        return (array) $this->http->ensureSuccessful($this->http->send(fn (PendingRequest $r) => $r->post('/api/dashboards/db', [
            'dashboard' => $dashboard,
            'folderUid' => $folderUid,
            'overwrite' => true,
            'message' => $message,
        ])))->json();
    }

    /**
     * @param  list<string>  $tags
     */
    public function createAnnotation(int $timeMs, ?int $timeEndMs, array $tags, string $text): int
    {
        $response = $this->http->ensureSuccessful($this->http->send(fn (PendingRequest $r) => $r->post('/api/annotations', array_filter([
            'time' => $timeMs,
            'timeEnd' => $timeEndMs,
            'tags' => array_values($tags),
            'text' => $text,
        ], fn ($v) => $v !== null))));

        return (int) $response->json('id');
    }

    /**
     * @param  array{time?: int, timeEnd?: int, tags?: list<string>, text?: string}  $changes
     */
    public function updateAnnotation(int $id, array $changes): void
    {
        $this->http->ensureSuccessful($this->http->send(fn (PendingRequest $r) => $r->patch("/api/annotations/{$id}", $changes)));
    }
}
