<?php

namespace Falak\Previews\Application;

use Falak\Previews\Domain\Models\Preview;
use Falak\SourceControl\Contracts\Exceptions\SourceControlException;
use Falak\SourceControl\Contracts\SourceControlGateway;
use Illuminate\Support\Facades\Log;

/**
 * Falak's one comment per pull request (posted once, then edited) and its `falak/preview` commit status. Both are
 * public: they carry generic states only. Errors, server names, script output and the basic auth password stay in
 * Falak, where project members see them.
 */
final class PreviewNotifier
{
    public const CONTEXT = 'falak/preview';

    public function __construct(private readonly SourceControlGateway $gateway) {}

    public function update(Preview $preview): void
    {
        $body = $this->body($preview);

        try {
            $id = $this->gateway->commentOnPullRequest($preview->connection_id, $preview->repository, $preview->number, $body, $preview->comment_id);

            if ($id !== $preview->comment_id) {
                $preview->forceFill(['comment_id' => $id])->save();
            }
        } catch (SourceControlException $e) {
            Log::warning('previews: comment not posted', ['preview' => $preview->id, 'error' => $e->getMessage()]);
        }

        $this->status($preview);
    }

    /** A one-off reply (e.g. to a `/falak preview` comment that can't approve). */
    public function reply(Preview $preview, string $body): void
    {
        try {
            $this->gateway->commentOnPullRequest($preview->connection_id, $preview->repository, $preview->number, $body);
        } catch (SourceControlException $e) {
            Log::warning('previews: reply not posted', ['preview' => $preview->id, 'error' => $e->getMessage()]);
        }
    }

    public function body(Preview $preview): string
    {
        $sha = substr($preview->head_sha, 0, 7);
        $lines = ["**Falak preview** for #{$preview->number} (`{$sha}`): ".$this->headline($preview), ''];

        if ($preview->status === Preview::READY && $preview->urls !== []) {
            $lines[] = '| Service | URL |';
            $lines[] = '| --- | --- |';

            foreach ($preview->urls ?? [] as $service => $url) {
                $lines[] = '| '.str_replace('|', '\\|', (string) $service)." | {$url} |";
            }

            $lines[] = '';
            $lines[] = $preview->basic_username !== null
                ? 'These previews are protected with basic auth. Project members find the credentials in Falak (project → Previews).'
                : 'These previews are public.';
        }

        $lines[] = '';
        $lines[] = '<sub>'.$this->link($preview).'</sub>';

        return implode("\n", $lines);
    }

    private function headline(Preview $preview): string
    {
        return match ($preview->status) {
            Preview::WAITING_APPROVAL => 'waiting for approval. This pull request comes from a fork: a project member approves it with a `/falak preview` comment or in Falak. Previews of forks never get secrets.',
            Preview::QUEUED => 'waiting, the project\'s limit of concurrent previews is reached. It starts when another preview closes.',
            Preview::CREATING => 'setting up.',
            Preview::DEPLOYING => 'deploying.',
            Preview::READY => 'ready.',
            Preview::FAILED => 'failed. Project members see why in Falak.',
            Preview::CLOSED => 'removed.',
            default => $preview->status.'.',
        };
    }

    private function status(Preview $preview): void
    {
        [$state, $description] = match ($preview->status) {
            Preview::READY => ['success', 'Preview is live'],
            Preview::FAILED => ['failure', 'Preview failed'],
            Preview::CLOSED => ['success', 'Preview removed'],
            Preview::WAITING_APPROVAL => ['pending', 'Waiting for a member to approve'],
            Preview::QUEUED => ['pending', 'Waiting: preview limit reached'],
            default => ['pending', 'Preview deploying'],
        };

        try {
            $this->gateway->setCommitStatus($preview->connection_id, $preview->repository, $preview->head_sha, $state, self::CONTEXT, $description,
                $preview->status === Preview::READY ? (array_values($preview->urls ?? [])[0] ?? null) : $this->url($preview));
        } catch (SourceControlException $e) {
            Log::warning('previews: commit status not set', ['preview' => $preview->id, 'error' => $e->getMessage()]);
        }
    }

    private function url(Preview $preview): string
    {
        return rtrim((string) config('app.url'), '/')."/projects/{$preview->project_id}/previews";
    }

    private function link(Preview $preview): string
    {
        return "[Open in Falak]({$this->url($preview)})";
    }
}
