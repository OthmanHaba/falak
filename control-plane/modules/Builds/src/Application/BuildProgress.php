<?php

namespace Falak\Builds\Application;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Falak\Builds\Application\Artifacts\ArtifactStorage;
use Falak\Builds\Contracts\BuildStatus;
use Falak\Builds\Domain\Models\Build;
use Falak\Builds\Domain\Models\BuildLog;
use Falak\Builds\Events\BuildCancelled;
use Falak\Builds\Events\BuildFailed;
use Falak\Builds\Events\BuildOutputReceived;
use Falak\Builds\Events\BuildSucceeded;
use Falak\Builds\Events\BuildUpdated;
use Throwable;

/**
 * Every build state transition and log line goes through here, so events and broadcasts are
 * emitted exactly once per transition. Terminal builds never change again.
 */
final class BuildProgress
{
    /** falak-builder finished exit code for timeouts (commands.ExitTimeout). */
    public const EXIT_TIMEOUT = 124;

    public function __construct(private readonly ArtifactStorage $storage) {}

    /**
     * Control-plane generated lines (no builder seq).
     *
     * @param  list<string>  $chunks
     */
    public function log(Build $build, array $chunks, string $stream = 'stdout'): void
    {
        $lines = [];

        foreach ($chunks as $data) {
            $lines[] = BuildLog::query()->create(['build_id' => $build->id, 'seq' => null, 'stream' => $stream, 'data' => $data, 'at' => now()])->toLine();
        }

        $this->broadcast($build, $lines);
    }

    /**
     * Builder output events; deduplicated on (build, seq) because delivery is at-least-once.
     *
     * @param  list<array{seq: int, stream: string, data: string, at: string}>  $events
     */
    public function output(Build $build, array $events): void
    {
        if ($events === []) {
            return;
        }

        $known = BuildLog::query()->where('build_id', $build->id)->whereIn('seq', array_column($events, 'seq'))->pluck('seq')->all();
        $known = array_flip(array_map('intval', $known));
        $lines = [];

        foreach ($events as $event) {
            if (isset($known[$event['seq']])) {
                continue;
            }

            try {
                $lines[] = BuildLog::query()->create([
                    'build_id' => $build->id,
                    'seq' => $event['seq'],
                    'stream' => $event['stream'] === 'stderr' ? 'stderr' : 'stdout',
                    'data' => $event['data'],
                    'at' => self::time($event['at']),
                ])->toLine();
            } catch (UniqueConstraintViolationException) {
                // Delivered concurrently by a retry.
            }

            $known[$event['seq']] = true;
        }

        $this->broadcast($build, $lines);
    }

    public function started(Build $build): void
    {
        if ($build->status !== BuildStatus::Assigned && $build->status !== BuildStatus::Queued) {
            return;
        }

        $build->forceFill(['status' => BuildStatus::Running, 'started_at' => $build->started_at ?? now()])->save();
        $this->updated($build);
    }

    public function progress(Build $build, float $progress): void
    {
        if (! $build->status->isTerminal()) {
            $build->forceFill(['progress' => max(0, min(1, $progress))])->save();
        }
    }

    /**
     * The builder's finished event.
     *
     * @param  array<string, mixed>|null  $result  falak-builder Result
     */
    public function finished(Build $build, int $exitCode, ?array $result, ?string $error): void
    {
        if ($build->status->isTerminal()) {
            return;
        }

        $result ??= [];

        if (is_string($result['commit'] ?? null) && preg_match('/^[0-9a-f]{7,64}$/i', $result['commit']) === 1) {
            $build->resolved_commit = strtolower($result['commit']);
        }

        $build->duration_ms = is_numeric($result['duration_ms'] ?? null) ? (int) $result['duration_ms'] : null;

        if ($exitCode !== 0 || ($error !== null && $error !== '')) {
            $status = $exitCode === self::EXIT_TIMEOUT ? BuildStatus::TimedOut : BuildStatus::Failed;
            $this->fail($build, $error ?: "Build exited with code {$exitCode}.", $status, $exitCode);

            return;
        }

        $problem = match (true) {
            $build->mode === 'docker' && is_array($result['compose'] ?? null) => $this->acceptCompose($build, $result['compose']),
            $build->mode === 'docker' => $this->acceptImage($build, $result),
            default => $this->acceptArtifact($build, $result),
        };

        if ($problem !== null) {
            $this->log($build, ["{$problem}\n"], 'stderr');
            $this->fail($build, $problem, BuildStatus::Failed, $exitCode);

            return;
        }

        $build->forceFill([
            'status' => BuildStatus::Succeeded,
            'exit_code' => 0,
            'error' => null,
            'progress' => 1.0,
            'manifest' => is_array($result['manifest'] ?? null) ? $result['manifest'] : null,
            'started_at' => $build->started_at ?? now(),
            'finished_at' => now(),
            'duration_ms' => $build->duration_ms ?? $this->elapsed($build),
        ])->save();

        $this->updated($build);
        BuildSucceeded::dispatch($build->id, $build->organization_id, $build->site_id, $build->mode, $build->effectiveCommit(), $build->deployment_id, (int) $build->duration_ms);
    }

    public function fail(Build $build, string $error, BuildStatus $status = BuildStatus::Failed, ?int $exitCode = null): void
    {
        if ($build->status->isTerminal()) {
            return;
        }

        $build->forceFill([
            'status' => $status,
            'error' => mb_substr($error, 0, 2000),
            'exit_code' => $exitCode,
            'finished_at' => now(),
            'duration_ms' => $build->duration_ms ?? $this->elapsed($build),
        ])->save();

        $this->updated($build);
        BuildFailed::dispatch($build->id, $build->organization_id, $build->site_id, $build->site_slug, $status->value, (string) $build->error, $build->effectiveCommit(), $build->deployment_id);
    }

    public function cancel(Build $build, string $reason): bool
    {
        if ($build->status->isTerminal()) {
            return false;
        }

        $build->forceFill(['status' => BuildStatus::Cancelled, 'error' => $reason, 'finished_at' => now(), 'duration_ms' => $this->elapsed($build)])->save();
        $this->log($build, ["Build cancelled: {$reason}\n"], 'stderr');
        $this->updated($build);
        BuildCancelled::dispatch($build->id, $build->organization_id, $build->site_id, $build->deployment_id);

        return true;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function acceptArtifact(Build $build, array $result): ?string
    {
        $artifact = is_array($result['artifact'] ?? null) ? $result['artifact'] : [];
        $sha = strtolower((string) ($artifact['sha256'] ?? ''));
        $format = (string) ($artifact['format'] ?? 'tar.gz');

        if (preg_match('/^[a-f0-9]{64}$/', $sha) !== 1) {
            return 'The builder reported no artifact checksum.';
        }

        if (! in_array($format, ['tar.gz', 'tar.zst', 'tar'], true)) {
            return "Unsupported artifact format [{$format}].";
        }

        if ($build->artifact_key === null) {
            return 'The build has no artifact upload target.';
        }

        $stored = $this->storage->checksum($build->artifact_key);

        if ($this->storage->driver() === 'local' && $stored !== $sha) {
            return $stored === null ? 'The artifact was not uploaded.' : 'The uploaded artifact does not match the reported checksum.';
        }

        $build->forceFill([
            'artifact_sha256' => $sha,
            'artifact_size' => is_numeric($artifact['size_bytes'] ?? null) ? (int) $artifact['size_bytes'] : $this->storage->size($build->artifact_key),
            'artifact_format' => $format,
        ]);

        return null;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function acceptImage(Build $build, array $result): ?string
    {
        $image = is_array($result['image'] ?? null) ? $result['image'] : [];
        $ref = (string) ($image['ref'] ?? '');

        if ($ref === '') {
            return 'The builder reported no image.';
        }

        $digest = (string) ($image['digest'] ?? '');

        $build->forceFill(['image_ref' => $ref, 'image_digest' => preg_match('/^sha256:[a-f0-9]{64}$/', $digest) === 1 ? $digest : null]);

        return null;
    }

    /**
     * falak-builder ComposeResult: the compose file and one pinned image per `build:` service.
     *
     * @param  array<string, mixed>  $compose
     */
    private function acceptCompose(Build $build, array $compose): ?string
    {
        $content = $compose['content'] ?? null;

        if (! is_string($content) || trim($content) === '') {
            return 'The builder reported no compose file.';
        }

        $images = [];

        foreach ((array) ($compose['images'] ?? []) as $service => $image) {
            $pinned = is_array($image) ? (string) ($image['pinned'] ?? '') : '';
            $ref = $pinned !== '' ? $pinned : (is_array($image) ? (string) ($image['ref'] ?? '') : '');

            if ($ref === '') {
                return "The builder reported no image for service {$service}.";
            }

            $images[(string) $service] = $ref;
        }

        // Repository files the project mounts (newer builders): shipped with each release under repo/.
        $assets = [];

        foreach ((array) ($compose['assets'] ?? []) as $asset) {
            $path = is_array($asset) ? (string) ($asset['path'] ?? '') : '';

            if (! self::validAssetPath($path) || ! is_string($asset['content'] ?? null)) {
                return "The builder reported an invalid repository file ({$path}).";
            }

            $assets[] = ['path' => $path, 'content' => $asset['content'], 'mode' => (int) ($asset['mode'] ?? 0o644) === 0o755 ? 0o755 : 0o644];
        }

        $build->forceFill(['compose' => array_filter([
            'file' => (string) ($compose['file'] ?? 'compose.yaml'),
            'content' => $content,
            'images' => $images,
            // Only builders that merge projects report `files`; older ones leave paths to the release directory.
            'files' => is_array($compose['files'] ?? null) ? array_values(array_map('strval', $compose['files'])) : null,
            'assets' => is_array($compose['files'] ?? null) ? $assets : null,
            'missing' => is_array($compose['missing'] ?? null) ? array_values(array_map('strval', $compose['missing'])) : null,
        ], fn ($value) => $value !== null)]);

        return null;
    }

    /**
     * A relative repository path without ".", ".." or empty segments, backslashes or control characters (any other
     * name is fine). Same rule as falak-builder and the agent's docker.compose.* assets.
     */
    public static function validAssetPath(string $path): bool
    {
        if ($path === '' || strlen($path) > 512 || str_starts_with($path, '/') || str_contains($path, '\\') || preg_match('/[\x00-\x1f\x7f]/', $path) === 1) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array{seq: int, stream: string, data: string, at: string}>  $lines
     */
    private function broadcast(Build $build, array $lines): void
    {
        if ($lines === []) {
            return;
        }

        BuildOutputReceived::dispatch($build->id, $build->deployment_id, $build->status->value, $lines);
    }

    private function updated(Build $build): void
    {
        BuildUpdated::dispatch($build->id, $build->status->value, $build->progress, $build->error);
    }

    private function elapsed(Build $build): ?int
    {
        return $build->started_at ? (int) $build->started_at->diffInMilliseconds(now(), true) : null;
    }

    private static function time(string $at): Carbon
    {
        try {
            return Carbon::parse($at);
        } catch (Throwable) {
            return now();
        }
    }
}
