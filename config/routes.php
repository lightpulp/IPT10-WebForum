<?php
declare(strict_types=1);

use Bramus\Router\Router;

/**
 * Route table: URL pattern -> controller method.
 *
 * ID segments use ([^/]+) on purpose, so a malformed ID such as /discussions/abc
 * reaches the controller, which answers 400. A numeric but unknown ID answers 404.
 * Anything that matches no route is a router 404.
 */
return static function (Router $router, array $c): void {
    // Public (read)
    $router->get('/',                       [$c['discussions'], 'index']);
    $router->get('/discussions',            [$c['discussions'], 'index']);
    $router->get('/discussions/([^/]+)',    [$c['discussions'], 'show']);

    // Accounts
    $router->get('/register',  [$c['auth'], 'showRegister']);
    $router->post('/register', [$c['auth'], 'register']);
    $router->get('/login',     [$c['auth'], 'showLogin']);
    $router->post('/login',    [$c['auth'], 'login']);
    $router->post('/logout',   [$c['auth'], 'logout']);

    // Authenticated writes
    $router->post('/discussions/([^/]+)/comments',                       [$c['comments'], 'storeTopLevel']);
    $router->post('/discussions/([^/]+)/comments/([^/]+)/replies',       [$c['comments'], 'storeReply']);
};
