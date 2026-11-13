<?php
declare(strict_types=1);

/**
 * Application configuration.
 *
 * Credentials are read from environment variables so nothing secret is committed.
 * For a quick local override you may also create config/local.php (git-ignored)
 * that returns an array; it is merged over these defaults.
 */

$env = static function (string $name, string $default = ''): string {
    $value = getenv($name);
    return $value === false ? $default : $value;
};

$config = [
    'db' => [
        'host'    => $env('DB_HOST', '127.0.0.1'),
        'port'    => $env('DB_PORT', '3306'),
        'name'    => $env('DB_NAME', 'forum_lab'),
        'user'    => $env('DB_USER', 'forum_app'),
        'pass'    => $env('DB_PASS', ''),
        'charset' => 'utf8mb4',
    ],
    'session' => [
        'name' => 'forum_sid',
    ],
    'forum' => [
        // Indentation stops growing at this displayed depth; the depth label stays accurate.
        'visual_indent_cap' => 8,
        // Server-enforced limit: a new reply may not be deeper than this (displayed depth).
        'max_reply_depth'   => 25,
        // Traversal limit: the renderer never visits more than this many comments.
        'max_render_nodes'  => 1000,
        // Hard guard for the renderer's recursion, independent of the write limit.
        'max_render_depth'  => 100,
        'max_body_length'   => 5000,
    ],
];

if (is_file(__DIR__ . '/local.php')) {
    $config = array_replace_recursive($config, require __DIR__ . '/local.php');
}

return $config;
