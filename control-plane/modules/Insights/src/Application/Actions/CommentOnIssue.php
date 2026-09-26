<?php

namespace Kiln\Insights\Application\Actions;

use Kiln\Insights\Domain\Models\Issue;
use Kiln\Insights\Domain\Models\IssueComment;

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
