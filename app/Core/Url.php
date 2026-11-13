<?php
declare(strict_types=1);

namespace App\Core;

/** Builds URLs that keep working when the app lives in a sub-directory. */
final class Url
{
    /** '' when served from the web root, otherwise e.g. '/forum-refactor/public'. */
    public static function base(): string
    {
        $override = getenv('FORUM_BASE_PATH');
        if ($override !== false) {
            return rtrim($override, '/');
        }
        $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        return ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');
    }

    public static function to(string $path): string
    {
        return self::base() . '/' . ltrim($path, '/');
    }
}
