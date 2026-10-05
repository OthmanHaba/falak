<?php

namespace Falak\Insights\Application\Actions;

use Falak\Insights\Domain\Models\Issue;
use Falak\Insights\Domain\Models\IssueComment;

final class CommentOnIssue
{
    public function __invoke(Issue $issue, string $userId, string $body): IssueComment
    {
        /** @var IssueComment $comment */
        $comment = $issue->comments()->create(['user_id' => $userId, 'body' => trim($body)]);
        $issue->record('commented', $userId, ['comment_id' => $comment->id]);

        return $comment;
    }
}
