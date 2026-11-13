<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

/** Data access for the users table. */
final class User
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, username, email FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Look an account up by username or email (including its password hash). */
    public function findByLogin(string $login): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, username, email, password_hash FROM users
             WHERE username = :username OR email = :email LIMIT 1'
        );
        $stmt->execute(['username' => $login, 'email' => $login]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Insert a user. Throws PDOException with SQLSTATE 23000 when the username
     * or email is already taken (enforced by the UNIQUE keys).
     */
    public function create(string $username, string $email, string $passwordHash): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (username, email, password_hash) VALUES (:username, :email, :hash)'
        );
        $stmt->execute(['username' => $username, 'email' => $email, 'hash' => $passwordHash]);
        return (int) $this->pdo->lastInsertId();
    }
}
