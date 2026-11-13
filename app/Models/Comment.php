<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

/** Data access for the comments table. */
final class Comment
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Comments of one discussion with author names, oldest first.
     * A parent always has a lower ID than its children (it must exist when the
     * child is inserted), so cutting the list at $limit never orphans a loaded comment.
     */
    public function forDiscussion(int $discussionId, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.parent_id, c.body, c.created_at, u.username
             FROM comments c
             JOIN users u ON u.id = c.user_id
             WHERE c.discussion_id = :discussion_id
             ORDER BY c.id ASC
             LIMIT :max'
        );
        $stmt->bindValue('discussion_id', $discussionId, PDO::PARAM_INT);
        $stmt->bindValue('max', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** A comment, only if it belongs to the given discussion. */
    public function findInDiscussion(int $discussionId, int $commentId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, parent_id FROM comments WHERE id = :id AND discussion_id = :discussion_id'
        );
        $stmt->execute(['id' => $commentId, 'discussion_id' => $discussionId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Displayed depth of an existing comment (top-level = 0), found by walking up
     * the parent links. Stops after $limit + 1 steps so a damaged chain cannot loop
     * forever; the returned value is then larger than $limit.
     */
    public function depthOf(int $discussionId, int $commentId, int $limit): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT parent_id FROM comments WHERE id = :id AND discussion_id = :discussion_id'
        );
        $depth = 0;
        $current = $commentId;
        while ($depth <= $limit) {
            $stmt->execute(['id' => $current, 'discussion_id' => $discussionId]);
            $row = $stmt->fetch();
            if ($row === false) {
                return null;
            }
            if ($row['parent_id'] === null) {
                return $depth;
            }
            $current = (int) $row['parent_id'];
            $depth++;
        }
        return $depth;
    }

    /** Insert a comment; returns its new ID. The author is always supplied by the caller from the session. */
    public function create(int $discussionId, ?int $parentId, int $userId, string $body): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO comments (discussion_id, parent_id, user_id, body)
             VALUES (:discussion_id, :parent_id, :user_id, :body)'
        );
        $stmt->bindValue('discussion_id', $discussionId, PDO::PARAM_INT);
        $stmt->bindValue('parent_id', $parentId, $parentId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue('user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue('body', $body, PDO::PARAM_STR);
        $stmt->execute();
        return (int) $this->pdo->lastInsertId();
    }
}
