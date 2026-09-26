<?php

namespace Kiln\Insights\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Kiln\Insights\Application\Actions\CommentOnIssue;
use Kiln\Insights\Domain\Models\Issue;
use Kiln\Insights\Domain\Models\IssueComment;
use Kiln\Kernel\Http\Controller;

final class CommentController extends Controller
{
    public function store(Request $request, Issue $issue, CommentOnIssue $comment): RedirectResponse
    {
        $this->authorize('update', $issue);
        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);

        $comment($issue, (string) $request->user()?->getAuthIdentifier(), $data['body']);

        return back();
    }

    public function destroy(Request $request, Issue $issue, IssueComment $comment): RedirectResponse
    {
        $this->authorize('view', $issue);
        abort_unless($comment->issue_id === $issue->id, 404);
        abort_unless($comment->user_id === $request->user()?->getAuthIdentifier(), 403, 'You can only delete your own comments.');

        $comment->delete();
        $issue->record('comment_deleted', $comment->user_id, ['comment_id' => $comment->id]);

        return back();
    }
}
