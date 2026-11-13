<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\User;

/** Who is logged in, based on the session. */
final class Auth
{
    private ?array $user = null;
    private bool $loaded = false;

    public function __construct(private User $users)
    {
    }

    /** @return array{id:int,username:string,email:string}|null */
    public function user(): ?array
    {
        if (!$this->loaded) {
            $this->loaded = true;
            $id = $_SESSION['user_id'] ?? null;
            if (is_int($id)) {
                $row = $this->users->findById($id);
                if ($row !== null) {
                    $this->user = [
                        'id'       => (int) $row['id'],
                        'username' => $row['username'],
                        'email'    => $row['email'],
                    ];
                }
            }
        }
        return $this->user;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function login(int $userId): void
    {
        session_regenerate_id(true);   // defeats session fixation
        $_SESSION['user_id'] = $userId;
        Csrf::rotate();
        $this->user = null;
        $this->loaded = false;
    }

    public function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);   // old session ID is deleted on the server
        $this->user = null;
        $this->loaded = true;
    }
}
