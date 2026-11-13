<?php
declare(strict_types=1);

namespace App\Core;

final class Http
{
    /** Redirect (303 See Other, so the browser follows with GET) and stop. */
    public static function redirect(string $path, int $status = 303): never
    {
        header('Location: ' . Url::to($path), true, $status);
        exit;
    }

    public static function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        // No inline scripts or styles anywhere, so a strict policy works.
        header("Content-Security-Policy: default-src 'self'; img-src 'self'; "
            . "form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
    }
}
