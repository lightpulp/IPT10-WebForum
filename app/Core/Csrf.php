<?php
declare(strict_types=1);

namespace App\Core;

/** Session-backed CSRF token. Every state-changing form carries it. */
final class Csrf
{
    public const FIELD = 'csrf_token';

    public static function token(): string
    {
        if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function rotate(): void
    {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    public static function isValid(string $submitted): bool
    {
        $expected = $_SESSION['csrf'] ?? null;
        return is_string($expected) && $submitted !== '' && hash_equals($expected, $submitted);
    }

    /** Validate the token in the current POST request. */
    public static function verifyRequest(): bool
    {
        return self::isValid(Request::post(self::FIELD));
    }
}
