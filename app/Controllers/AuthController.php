<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Http;
use App\Core\Request;
use App\Core\View;
use App\Models\User;
use PDOException;

/** Registration, login and logout. */
final class AuthController
{
    /** A valid bcrypt hash of a random string. Verified against when the account is unknown, so timing does not reveal which usernames exist. */
    private const DUMMY_HASH = '$2y$10$RfYti/cmnkqIUR0GxTN2bec.reKjnoPKSeRoRMqGh83QAI5dvmViO';

    public function __construct(private View $view, private Auth $auth, private User $users)
    {
    }

    public function showRegister(): void
    {
        if ($this->auth->check()) {
            Http::redirect('/');
        }
        $this->renderRegister([], '', '', 200);
    }

    public function register(): void
    {
        if (!$this->checkCsrf()) {
            return;
        }

        $username = trim(Request::post('username'));
        $email    = strtolower(trim(Request::post('email')));
        $password = Request::post('password');
        $confirm  = Request::post('password_confirm');

        $errors = [];
        if (preg_match('/^[A-Za-z0-9_]{3,30}$/', $username) !== 1) {
            $errors[] = 'Username must be 3 to 30 characters: letters, numbers or underscores.';
        }
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Enter a valid email address.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        } elseif (strlen($password) > 72) {
            $errors[] = 'Password must be at most 72 bytes (a bcrypt limit).';
        }
        if ($password !== $confirm) {
            $errors[] = 'The two passwords do not match.';
        }

        if ($errors === []) {
            try {
                $this->users->create($username, $email, password_hash($password, PASSWORD_DEFAULT));
                Flash::add('success', 'Account created. Log in to start replying.');
                Http::redirect('/login');
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') {   // 23000 = UNIQUE violation (duplicate account)
                    throw $e;
                }
                $errors[] = 'That username or email is already registered.';
            }
        }

        $this->renderRegister($errors, $username, $email, 422);
    }

    public function showLogin(): void
    {
        if ($this->auth->check()) {
            Http::redirect('/');
        }
        $this->renderLogin([], '', self::safeNext(Request::query('next')), 200);
    }

    public function login(): void
    {
        if (!$this->checkCsrf()) {
            return;
        }

        $login    = trim(Request::post('login'));
        $password = Request::post('password');
        $next     = self::safeNext(Request::post('next'));

        $user = ($login !== '' && strlen($login) <= 254) ? $this->users->findByLogin($login) : null;
        $hash = $user['password_hash'] ?? null;

        $ok = false;
        if (is_string($hash) && $hash !== '' && strlen($password) <= 1000) {
            $ok = password_verify($password, $hash);
        } else {
            // NULL or empty hash (seed users) or unknown account: never authenticates.
            password_verify($password, self::DUMMY_HASH);
        }

        if ($user === null || !$ok) {
            $this->renderLogin(['Incorrect username or password.'], $login, $next, 401);
            return;
        }

        $this->auth->login((int) $user['id']);   // regenerates the session ID
        Flash::add('success', 'Logged in as ' . $user['username'] . '.');
        Http::redirect($next ?? '/');
    }

    public function logout(): void
    {
        if (!$this->checkCsrf()) {
            return;
        }
        $this->auth->logout();
        Flash::add('success', 'You have been logged out.');
        Http::redirect('/');
    }

    /** Only an internal discussion path is allowed as a post-login destination (no open redirect). */
    private static function safeNext(string $value): ?string
    {
        return preg_match('#^/discussions/[1-9][0-9]{0,9}$#', $value) === 1 ? $value : null;
    }

    private function checkCsrf(): bool
    {
        if (Csrf::verifyRequest()) {
            return true;
        }
        $this->view->error(403, 'Form expired', 'Your form token was missing or invalid. Go back, reload the page and try again.');
        return false;
    }

    /** @param list<string> $errors */
    private function renderRegister(array $errors, string $username, string $email, int $status): void
    {
        $this->view->render('register', [
            'pageTitle' => 'Create an account',
            'hasErrors' => $errors !== [],
            'errors'    => $errors,
            'old'       => ['username' => $username, 'email' => $email],
        ], $status);
    }

    /** @param list<string> $errors */
    private function renderLogin(array $errors, string $login, ?string $next, int $status): void
    {
        $this->view->render('login', [
            'pageTitle' => 'Log in',
            'hasErrors' => $errors !== [],
            'errors'    => $errors,
            'old'       => ['login' => $login],
            'next'      => $next,
        ], $status);
    }
}
