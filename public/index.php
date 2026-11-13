<?php
declare(strict_types=1);

/**
 * Front controller: the single public entry point.
 * Every request is routed from here (see config/routes.php).
 */

// With `php -S`, let the built-in server deliver real files (CSS, JS, images) itself.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if ($path !== '/' && is_file(__DIR__ . $path)) {
        return false;
    }
}

$root = dirname(__DIR__);

if (!is_file($root . '/vendor/autoload.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Dependencies are missing. Run: composer install\n");
}
require $root . '/vendor/autoload.php';

use App\Controllers\AuthController;
use App\Controllers\CommentController;
use App\Controllers\DiscussionController;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Http;
use App\Core\Session;
use App\Core\Url;
use App\Core\View;
use App\Models\Comment;
use App\Models\Discussion;
use App\Models\User;
use Bramus\Router\Router;

$config = require $root . '/config/config.php';

Http::securityHeaders();
Session::start($config['session']['name']);

// Last-resort handler: log the details, show nothing sensitive.
$view = new View($root . '/app/Views', static function () use (&$auth): array {
    return [
        'base'     => Url::base(),
        'user'     => $auth?->user(),
        'loggedIn' => $auth?->check() ?? false,
        'csrf'     => Csrf::token(),
        'flash'    => Flash::pull(),
    ];
});

set_exception_handler(static function (Throwable $e) use ($view): void {
    error_log(sprintf('Unhandled %s: %s in %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
    if (!headers_sent()) {
        $view->error(500, 'Something went wrong', 'The page could not be shown. Please try again later.');
    }
});

// Model layer (PDO) -> services -> controllers.
$pdo         = Database::connect($config['db']);
$users       = new User($pdo);
$discussions = new Discussion($pdo);
$comments    = new Comment($pdo);
$auth        = new Auth($users);

$controllers = [
    'discussions' => new DiscussionController($view, $discussions, $comments, $config['forum']),
    'comments'    => new CommentController($view, $auth, $discussions, $comments, $config['forum']),
    'auth'        => new AuthController($view, $auth, $users),
];

$router = new Router();
$router->setBasePath(Url::base() . '/');
$router->set404(static function () use ($view): void {
    $view->error(404, 'Page not found', 'That page does not exist.');
});

(require $root . '/config/routes.php')($router, $controllers);

$router->run();
