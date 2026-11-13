<?php
declare(strict_types=1);

namespace App\Core;

/** Small helpers for reading user input safely. */
final class Request
{
    /** A POST field as a string; arrays and missing fields become ''. */
    public static function post(string $key): string
    {
        $value = $_POST[$key] ?? '';
        return is_string($value) ? $value : '';
    }

    public static function query(string $key): string
    {
        $value = $_GET[$key] ?? '';
        return is_string($value) ? $value : '';
    }

    public static function isHttps(): bool
    {
        return !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    }

    /**
     * Parse a route ID. Only plain positive integers are accepted ("1", not "01",
     * "-1", "1.5", "1e3", "abc" or an overlong number). Returns null when malformed.
     */
    public static function id(string $raw): ?int
    {
        return preg_match('/^[1-9][0-9]{0,9}$/', $raw) === 1 ? (int) $raw : null;
    }
}
