<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

/** Data access for the discussions table. */
final class Discussion
{
    public function __construct(private PDO $pdo)
    {
    }

    /** One discussion with its author's name, or null. */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.id, d.title, d.body, d.created_at, u.username AS author
             FROM discussions d
             JOIN users u ON u.id = d.user_id
             WHERE d.id = :id'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** All discussions, newest first, with a comment count. */
    public function all(): array
    {
        return $this->pdo->query(
            'SELECT d.id, d.title, d.created_at, u.username AS author,
                    (SELECT COUNT(*) FROM comments c WHERE c.discussion_id = d.id) AS comment_count
             FROM discussions d
             JOIN users u ON u.id = d.user_id
             ORDER BY d.created_at DESC, d.id DESC'
        )->fetchAll();
    }
}
