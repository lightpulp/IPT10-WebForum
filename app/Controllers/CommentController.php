<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Http;
use App\Core\Request;
use App\Core\View;
use App\Models\Comment;
use App\Models\Discussion;
use PDOException;

/**
 * Write side: posting a top-level comment or a reply.
 *
 * Order of checks (mirrors the "post a reply" activity diagram):
 * logged in? -> CSRF token -> IDs -> discussion exists -> parent in same discussion
 * -> content valid -> depth limit -> insert with the SESSION user's ID -> redirect.
 */
final class CommentController
{
    /** @param array{max_reply_depth:int,max_body_length:int} $forum */
    public function __construct(
        private View $view,
        private Auth $auth,
        private Discussion $discussions,
        private Comment $comments,
        private array $forum
    ) {
    }

    /** POST /discussions/{id}/comments */
    public function storeTopLevel(string $rawDiscussionId): void
    {
        $this->store($rawDiscussionId, null);
    }

    /** POST /discussions/{id}/comments/{parentId}/replies */
    public function storeReply(string $rawDiscussionId, string $rawParentId): void
    {
        $this->store($rawDiscussionId, $rawParentId);
    }

    private function store(string $rawDiscussionId, ?string $rawParentId): void
    {
        // 1. Authentication is checked on the server for every write.
        $user = $this->auth->user();
        if ($user === null) {
            Flash::add('error', 'Log in to post a reply.');
            $id = Request::id($rawDiscussionId);
            Http::redirect($id === null ? '/login' : '/login?next=' . rawurlencode('/discussions/' . $id));
        }

        // 2. CSRF token from the session-backed form field.
        if (!Csrf::verifyRequest()) {
            $this->view->error(403, 'Form expired', 'Your form token was missing or invalid. Reload the discussion and try again.');
            return;
        }

        // 3. IDs: malformed -> 400.
        $discussionId = Request::id($rawDiscussionId);
        $parentId = $rawParentId === null ? null : Request::id($rawParentId);
        if ($discussionId === null || ($rawParentId !== null && $parentId === null)) {
            $this->view->error(400, 'Bad request', 'IDs must be positive whole numbers.');
            return;
        }

        // 4. The discussion must exist.
        if ($this->discussions->find($discussionId) === null) {
            $this->view->error(404, 'Discussion not found', 'There is no discussion with that ID.');
            return;
        }

        // 5. A non-null parent must exist AND belong to this same discussion.
        $depth = 0;
        if ($parentId !== null) {
            $maxDepth = $this->forum['max_reply_depth'];
            $parentDepth = $this->comments->depthOf($discussionId, $parentId, $maxDepth);
            if ($parentDepth === null) {
                $this->view->error(422, 'Cannot reply there', 'That comment does not exist in this discussion.');
                return;
            }
            $depth = $parentDepth + 1;
        }

        // 6. Content: trimmed, valid UTF-8, no control characters, 1..max length.
        $body = trim(str_replace("\r\n", "\n", Request::post('body')));
        $error = $this->validateBody($body);
        if ($error !== null) {
            Flash::add('error', $error);
            Http::redirect('/discussions/' . $discussionId);
        }

        // 7. Depth limit (cycles are impossible: parent links are only set on insert, to an existing row).
        if ($depth > $this->forum['max_reply_depth']) {
            $this->view->error(
                422,
                'Reply is too deep',
                'Replies can be nested at most ' . $this->forum['max_reply_depth'] . ' levels deep. Reply to a higher comment instead.'
            );
            return;
        }

        // 8. Insert. user_id comes from the session, never from the form.
        try {
            $newId = $this->comments->create($discussionId, $parentId, $user['id'], $body);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {   // composite foreign key refused the parent
                $this->view->error(422, 'Cannot reply there', 'That comment does not exist in this discussion.');
                return;
            }
            throw $e;
        }

        // 9. Post/Redirect/Get so a refresh does not post twice.
        Flash::add('success', 'Your reply was posted.');
        Http::redirect('/discussions/' . $discussionId . '#comment-' . $newId);
    }

    private function validateBody(string $body): ?string
    {
        if ($body === '') {
            return 'Write something before posting.';
        }
        if (!mb_check_encoding($body, 'UTF-8')) {
            return 'Your reply contains invalid characters.';
        }
        if (mb_strlen($body, 'UTF-8') > $this->forum['max_body_length']) {
            return 'Replies can be at most ' . $this->forum['max_body_length'] . ' characters.';
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $body) === 1) {
            return 'Your reply contains invalid characters.';
        }
        return null;
    }
}
