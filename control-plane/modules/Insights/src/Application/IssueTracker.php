<?php

namespace Kiln\Insights\Application;

use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Kiln\Insights\Contracts\IssueKind;
use Kiln\Insights\Contracts\IssueStatus;
use Kiln\Insights\Domain\Models\Issue;
use Kiln\Insights\Events\IssueOpened;
use Kiln\Insights\Events\IssueRegressed;
use Kiln\Insights\Events\IssueResolved;

/**
 * Creates / updates issues by fingerprint and owns their status transitions:
 * new → open (IssueOpened); resolved + newer occurrence → open again (IssueRegressed);
 * ignored issues keep counting silently.
 */
final class IssueTracker
{
    public const OPENED = 'opened';

    public const REGRESSED = 'regressed';

    public const UPDATED = 'updated';

    public const IGNORED = 'ignored';

    /**
     * Record $count occurrences of the issue identified by ($organizationId, $kind, $fingerprint).
     * Must be called outside of a transaction; events are dispatched after commit.
     *
     * @param  array<string, mixed>  $attributes  site_id, server_id, title, culprit, exception_type, event_type, last_trace_id, sample, meta
     * @param  list<string>  $userHashes  affected (hashed) user ids
     * @return array{0: Issue, 1: string} the issue and its transition
     */
    public function record(
        string $organizationId,
        IssueKind $kind,
        string $fingerprint,
        array $attributes,
        CarbonInterface $firstAt,
        CarbonInterface $lastAt,
        int $count = 1,
        int $unhandled = 0,
        array $userHashes = [],
    ): array {
        [$issue, $transition] = $this->attempt(fn () => DB::transaction(function () use ($organizationId, $kind, $fingerprint, $attributes, $firstAt, $lastAt, $count, $unhandled, $userHashes) {
            $issue = Issue::query()
                ->where('organization_id', $organizationId)
                ->where('kind', $kind)
                ->where('fingerprint', $fingerprint)
                ->lockForUpdate()
                ->first();

            if ($issue === null) {
                $issue = Issue::query()->create([
                    ...$attributes,
                    'organization_id' => $organizationId,
                    'kind' => $kind,
                    'fingerprint' => $fingerprint,
                    'status' => IssueStatus::Open,
                    'occurrences' => $count,
                    'unhandled_occurrences' => $unhandled,
                    'first_seen_at' => $firstAt,
                    'last_seen_at' => $lastAt,
                ]);
                $issue->record('opened');
                $this->addUsers($issue, $userHashes, $firstAt);

                return [$issue, self::OPENED];
            }

            $transition = self::UPDATED;
            $changes = array_filter($attributes, fn ($value) => $value !== null);
            $changes['occurrences'] = $issue->occurrences + $count;
            $changes['unhandled_occurrences'] = $issue->unhandled_occurrences + $unhandled;

            if ($lastAt->greaterThan($issue->last_seen_at)) {
                $changes['last_seen_at'] = $lastAt;
            } else {
                // Late (buffered) data must not move the "latest" sample backwards.
                unset($changes['sample'], $changes['last_trace_id'], $changes['meta']);
            }

            if ($firstAt->lessThan($issue->first_seen_at)) {
                $changes['first_seen_at'] = $firstAt;
            }

            if ($issue->status === IssueStatus::Resolved && $issue->resolved_at !== null && $lastAt->greaterThan($issue->resolved_at)) {
                $changes += ['status' => IssueStatus::Open, 'regressed_at' => now(), 'resolved_at' => null, 'resolved_by' => null];
                $changes['regressions'] = $issue->regressions + 1;
                $transition = self::REGRESSED;
            } elseif ($issue->status === IssueStatus::Ignored) {
                $transition = self::IGNORED;
            }

            $issue->forceFill($changes)->save();

            if ($transition === self::REGRESSED) {
                $issue->record('regressed');
            }

            $this->addUsers($issue, $userHashes, $firstAt);

            return [$issue, $transition];
        }));

        $this->announce($issue, $transition);

        return [$issue, $transition];
    }

    /**
     * Resolve an issue (user action or automatic recovery). Returns false when it was not open/ignored.
     */
    public function resolve(Issue $issue, ?string $userId, bool $automatic = false): bool
    {
        if ($issue->status === IssueStatus::Resolved) {
            return false;
        }

        $issue->forceFill(['status' => IssueStatus::Resolved, 'resolved_at' => now(), 'resolved_by' => $userId, 'ignored_at' => null])->save();
        $issue->record('resolved', $userId, $automatic ? ['automatic' => true] : []);

        IssueResolved::dispatch(...$this->eventArguments($issue));

        return true;
    }

    public function announce(Issue $issue, string $transition): void
    {
        match ($transition) {
            self::OPENED => IssueOpened::dispatch(...$this->eventArguments($issue)),
            self::REGRESSED => IssueRegressed::dispatch(...$this->eventArguments($issue)),
            default => null,
        };
    }

    /**
     * @param  list<string>  $hashes
     */
    private function addUsers(Issue $issue, array $hashes, CarbonInterface $at): void
    {
        $hashes = array_values(array_unique(array_filter($hashes)));

        if ($hashes === []) {
            return;
        }

        $inserted = 0;

        foreach (array_chunk($hashes, 500) as $chunk) {
            $inserted += DB::table('insights_issue_users')->insertOrIgnore(
                array_map(fn (string $hash) => ['issue_id' => $issue->id, 'user_hash' => $hash, 'first_seen_at' => $at], $chunk),
            );
        }

        if ($inserted > 0) {
            $issue->forceFill(['affected_users' => $issue->affected_users + $inserted])->save();
        }
    }

    /**
     * @return array{issueId: string, organizationId: string, siteId: ?string, serverId: ?string, kind: string, title: string, culprit: ?string, priority: string, url: string}
     */
    private function eventArguments(Issue $issue): array
    {
        return [
            'issueId' => $issue->id,
            'organizationId' => $issue->organization_id,
            'siteId' => $issue->site_id,
            'serverId' => $issue->server_id,
            'kind' => $issue->kind->value,
            'title' => $issue->title,
            'culprit' => $issue->culprit,
            'priority' => $issue->priority->value,
            'url' => $issue->url(),
        ];
    }

    /**
     * Two workers may race to create the same fingerprint; the loser retries and updates instead.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function attempt(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (UniqueConstraintViolationException) {
            return $callback();
        }
    }
}
