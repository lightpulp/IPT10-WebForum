<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Url;
use App\Core\View;
use App\Models\Comment;
use App\Models\Discussion;
use App\Support\CommentTree;
use DateTimeImmutable;
use DateTimeZone;

/** Public, read-only pages: the discussion list and one discussion with its reply tree. */
final class DiscussionController
{
    /** @param array{visual_indent_cap:int,max_reply_depth:int,max_render_nodes:int,max_render_depth:int,max_body_length:int} $forum */
    public function __construct(
        private View $view,
        private Discussion $discussions,
        private Comment $comments,
        private array $forum
    ) {
    }

    public function index(): void
    {
        $items = array_map(fn(array $row): array => [
            'id'           => (int) $row['id'],
            'title'        => $row['title'],
            'author'       => $row['author'],
            'commentCount' => (int) $row['comment_count'],
            'createdAt'    => self::formatUtc($row['created_at']),
            'createdIso'   => self::isoUtc($row['created_at']),
        ], $this->discussions->all());

        $this->view->render('discussion_list', [
            'pageTitle'   => 'Discussions',
            'discussions' => $items,
            'hasItems'    => $items !== [],
        ]);
    }

    /** GET /discussions/{id}. $rawId is the unvalidated URL segment. */
    public function show(string $rawId): void
    {
        // 1. Validate the ID. Malformed (not a positive integer) -> 400.
        $id = Request::id($rawId);
        if ($id === null) {
            $this->view->error(400, 'Bad request', 'The discussion ID must be a positive whole number.');
            return;
        }

        // 2. Fetch the discussion. Valid but missing -> 404. The comment query is not run.
        $discussion = $this->discussions->find($id);
        if ($discussion === null) {
            $this->view->error(404, 'Discussion not found', 'There is no discussion with that ID.');
            return;
        }

        // 3. Fetch comments (one extra row tells us whether the list was cut off).
        $limit = $this->forum['max_render_nodes'];
        $rows = $this->comments->forDiscussion($id, $limit + 1);

        // 4. Group by parent, then walk the groups to build the nested tree with depths.
        $grouped = CommentTree::groupByParent($rows);
        $tree = CommentTree::build($grouped, $this->presenter(), $limit, $this->forum['max_render_depth']);

        $this->view->render('discussion_show', [
            'pageTitle'    => $discussion['title'],
            'discussion'   => [
                'id'         => (int) $discussion['id'],
                'title'      => $discussion['title'],
                'body'       => $discussion['body'],
                'author'     => $discussion['author'],
                'avatar'     => Url::to('assets/default-avatar.svg'),
                'createdAt'  => self::formatUtc($discussion['created_at']),
                'createdIso' => self::isoUtc($discussion['created_at']),
            ],
            'comments'     => $tree['nodes'],
            'hasComments'  => $tree['nodes'] !== [],
            'commentCount' => $tree['count'],
            'truncated'    => $tree['truncated'] || count($rows) > $limit,
            'maxLength'    => $this->forum['max_body_length'],
        ]);
    }

    /** Returns the callable that turns one DB row into the data the comment template needs. */
    private function presenter(): callable
    {
        $cap = $this->forum['visual_indent_cap'];
        $maxReplyDepth = $this->forum['max_reply_depth'];

        return static function (array $row, int $depth, array $children) use ($cap, $maxReplyDepth): array {
            return [
                'id'          => (int) $row['id'],
                'author'      => $row['username'],
                'avatar'      => Url::to('assets/default-avatar.svg'),
                'body'        => $row['body'],
                'createdAt'   => self::formatUtc($row['created_at']),
                'createdIso'  => self::isoUtc($row['created_at']),
                'depth'       => $depth,                       // accurate label, never capped
                'indent'      => min($depth, $cap),            // what the CSS uses
                'hasChildren' => $children !== [],
                'replyCount'  => count($children),
                'children'    => $children,
                // Replies of a comment at depth >= cap are drawn at the same indentation.
                'repliesFlat' => $depth + 1 > $cap,
                'canReply'    => $depth + 1 <= $maxReplyDepth,
            ];
        };
    }

    private static function utc(string $dbValue): DateTimeImmutable
    {
        return new DateTimeImmutable($dbValue, new DateTimeZone('UTC'));
    }

    private static function formatUtc(string $dbValue): string
    {
        return self::utc($dbValue)->format('M j, Y H:i') . ' UTC';
    }

    private static function isoUtc(string $dbValue): string
    {
        return self::utc($dbValue)->format('Y-m-d\TH:i:s\Z');
    }
}
