<?php
declare(strict_types=1);

namespace App\Core;

use Mustache_Engine;
use Mustache_Loader_FilesystemLoader;

/** Renders Mustache templates. All {{ }} output is HTML-escaped. */
final class View
{
    private Mustache_Engine $mustache;

    /** @param callable():array $globals values added to every page (user, csrf token, flash...) */
    public function __construct(string $templateDir, private $globals)
    {
        $loader = new Mustache_Loader_FilesystemLoader($templateDir, ['extension' => '.mustache']);
        $this->mustache = new Mustache_Engine([
            'loader'          => $loader,
            'partials_loader' => $loader,
            // ENT_QUOTES also escapes ' so values are safe inside attributes too.
            'escape'          => static fn($value): string =>
                htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        ]);
    }

    public function render(string $template, array $data = [], int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo $this->mustache->render($template, $data + ($this->globals)());
    }

    public function error(int $status, string $title, string $message): void
    {
        $this->render('error', [
            'pageTitle' => $title,
            'status'    => $status,
            'title'     => $title,
            'message'   => $message,
        ], $status);
    }
}
