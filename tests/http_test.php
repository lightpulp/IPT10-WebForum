<?php
declare(strict_types=1);

/**
 * End-to-end checks that follow the lab's "Verify and explain" step.
 *
 * Needs: the database loaded from database/schema.sql, DB_* environment variables
 * set, and the app running (composer serve). Optional: FORUM_URL (default
 * http://127.0.0.1:8000). The script creates a throw-away user and removes
 * everything it created when it finishes.
 *
 * Run:  php tests/http_test.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\Database;

$baseUrl = rtrim(getenv('FORUM_URL') ?: 'http://127.0.0.1:8000', '/');
$config  = require dirname(__DIR__) . '/config/config.php';
$pdo     = Database::connect($config['db']);
$maxDepth = $config['forum']['max_reply_depth'];

/** A tiny HTTP client with its own cookie store; never follows redirects. */
final class Client
{
    /** @var array<string,string> */
    public array $cookies = [];
    public string $lastSetCookie = '';

    public function __construct(private string $base)
    {
    }

    /** @return array{status:int,body:string,location:?string,headers:string} */
    public function request(string $method, string $path, array $fields = []): array
    {
        $headers = '';
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$headers): int {
                $headers .= $line;
                return strlen($line);
            },
        ]);
        if ($this->cookies) {
            $pairs = [];
            foreach ($this->cookies as $k => $v) {
                $pairs[] = "$k=$v";
            }
            curl_setopt($ch, CURLOPT_COOKIE, implode('; ', $pairs));
        }
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        foreach (preg_split('/\r?\n/', $headers) as $line) {
            if (stripos($line, 'Set-Cookie:') === 0) {
                $this->lastSetCookie = trim(substr($line, 11));
                [$pair] = explode(';', $this->lastSetCookie, 2);
                [$name, $value] = explode('=', $pair, 2);
                $this->cookies[$name] = $value;
            }
        }
        $location = preg_match('/^Location:\s*(.+)$/mi', $headers, $m) ? trim($m[1]) : null;
        return ['status' => $status, 'body' => $body, 'location' => $location, 'headers' => $headers];
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    public function post(string $path, array $fields = []): array
    {
        return $this->request('POST', $path, $fields);
    }

    /** Fetch a page and return the CSRF token found in its first form. */
    public function token(string $path): string
    {
        $page = $this->get($path);
        return preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $page['body'], $m) ? $m[1] : '';
    }
}

$failures = 0;
function check(string $name, bool $condition): void
{
    global $failures;
    echo ($condition ? '[pass] ' : '[FAIL] ') . $name . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
}

$commentCount = static fn(): int => (int) $pdo->query('SELECT COUNT(*) FROM comments')->fetchColumn();

$suffix   = bin2hex(random_bytes(3));
$username = 'tester_' . $suffix;
$email    = $username . '@example.test';
$password = 'correct horse battery staple';
$userId   = 0;

// Remove everything this run creates (children first: higher IDs are deleted first).
register_shutdown_function(static function () use ($pdo, $username): void {
    try {
        $id = $pdo->prepare('SELECT id FROM users WHERE username = :u');
        $id->execute(['u' => $username]);
        $uid = $id->fetchColumn();
        if ($uid !== false) {
            $pdo->prepare('DELETE FROM comments WHERE user_id = :u ORDER BY id DESC')->execute(['u' => $uid]);
            $pdo->prepare('DELETE FROM users WHERE id = :u')->execute(['u' => $uid]);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'Cleanup failed: ' . $e->getMessage() . PHP_EOL);
    }
});

echo "== Public reading ==\n";
$guest = new Client($baseUrl);
$page = $guest->get('/discussions/1');
check('GET /discussions/1 is 200', $page['status'] === 200);
$order = ['Start with the PHP manual.', 'Try the examples as you read.', 'Build a small project too.',
          'Start with a forum.', 'Add replies next.', 'Which manual section first?', 'Practice SQL as well.'];
$positions = array_map(static fn($t) => strpos($page['body'], $t), $order);
check('all seven seed comments appear, in tree order',
    !in_array(false, $positions, true) && $positions === array_values(array_unique($positions)) && $positions === (function ($p) { $s = $p; sort($s); return $s; })($positions));
check('depth labels 0 to 4 are shown', preg_match_all('/Depth [0-4]</', $page['body']) === 7);
check('guest sees a login prompt, not a reply form', str_contains($page['body'], 'Log in to reply') && !str_contains($page['body'], 'name="body"'));
check('Content-Security-Policy header is sent', stripos($page['headers'], 'Content-Security-Policy:') !== false);
check('page markup has no inline script', !preg_match('/<script(?![^>]*\bsrc=)[^>]*>/', $page['body']));

echo "\n== Invalid and missing IDs ==\n";
foreach (['abc' => 400, '0' => 400, '-1' => 400, '1e3' => 400, '01' => 400, '99999999999' => 400, '99999' => 404] as $id => $expected) {
    check("GET /discussions/$id returns $expected", $guest->get('/discussions/' . $id)['status'] === $expected);
}
check('unknown path returns 404', $guest->get('/nope')['status'] === 404);

echo "\n== Guests cannot write ==\n";
$before = $commentCount();
$withToken = new Client($baseUrl);
$tok = $withToken->token('/login');
$r1 = $withToken->post('/discussions/1/comments', ['csrf_token' => $tok, 'body' => 'guest with a valid token']);
check('guest POST with a valid CSRF token is redirected to login', $r1['status'] === 303 && str_contains((string) $r1['location'], '/login'));
$direct = new Client($baseUrl);
$r2 = $direct->post('/discussions/1/comments', ['body' => 'direct guest post']);
$r3 = $direct->post('/discussions/1/comments/7/replies', ['body' => 'direct guest reply', 'user_id' => '1']);
check('direct guest POSTs are refused', $r2['status'] === 303 && $r3['status'] === 303);
check('no row was created by guests', $commentCount() === $before);

echo "\n== Registration ==\n";
$c = new Client($baseUrl);
$tok = $c->token('/register');
check('register with a bad CSRF token is 403', $c->post('/register', ['csrf_token' => 'bad', 'username' => $username, 'email' => $email, 'password' => $password, 'password_confirm' => $password])['status'] === 403);
check('register with mismatched passwords is 422', $c->post('/register', ['csrf_token' => $tok, 'username' => $username, 'email' => $email, 'password' => $password, 'password_confirm' => 'different'])['status'] === 422);
check('register with a short password is 422', $c->post('/register', ['csrf_token' => $tok, 'username' => $username, 'email' => $email, 'password' => 'short', 'password_confirm' => 'short'])['status'] === 422);
check('register with a bad username is 422', $c->post('/register', ['csrf_token' => $tok, 'username' => 'no spaces!', 'email' => $email, 'password' => $password, 'password_confirm' => $password])['status'] === 422);
$ok = $c->post('/register', ['csrf_token' => $tok, 'username' => $username, 'email' => $email, 'password' => $password, 'password_confirm' => $password]);
check('valid registration redirects to /login', $ok['status'] === 303 && str_contains((string) $ok['location'], '/login'));
$dup = $c->post('/register', ['csrf_token' => $tok, 'username' => strtoupper($username), 'email' => 'other_' . $email, 'password' => $password, 'password_confirm' => $password]);
check('duplicate username (any case) is rejected', $dup['status'] === 422);
$row = $pdo->prepare('SELECT id, password_hash FROM users WHERE username = :u');
$row->execute(['u' => $username]);
$user = $row->fetch();
$userId = (int) ($user['id'] ?? 0);
check('password is stored as a hash, never plaintext', is_string($user['password_hash'] ?? null) && $user['password_hash'] !== $password && password_verify($password, $user['password_hash']));

echo "\n== Login ==\n";
$tok = $c->token('/login');
check('login with a bad CSRF token is 403', $c->post('/login', ['csrf_token' => 'bad', 'login' => $username, 'password' => $password])['status'] === 403);
check('wrong password is 401', $c->post('/login', ['csrf_token' => $tok, 'login' => $username, 'password' => 'wrong password'])['status'] === 401);
check('seed user with NULL password hash can never log in', $c->post('/login', ['csrf_token' => $tok, 'login' => 'Alice', 'password' => ''])['status'] === 401
    && $c->post('/login', ['csrf_token' => $tok, 'login' => 'Alice', 'password' => 'anything'])['status'] === 401);
$sidBefore = $c->cookies['forum_sid'] ?? '';
$login = $c->post('/login', ['csrf_token' => $tok, 'login' => $username, 'password' => $password, 'next' => 'https://evil.test/']);
check('correct login redirects, and ignores an external "next" URL', $login['status'] === 303 && $login['location'] === '/');
check('session ID is regenerated on login', ($c->cookies['forum_sid'] ?? '') !== '' && $c->cookies['forum_sid'] !== $sidBefore);
check('session cookie is HttpOnly and SameSite', stripos($c->lastSetCookie, 'HttpOnly') !== false && stripos($c->lastSetCookie, 'SameSite=Lax') !== false);
$page = $c->get('/discussions/1');
check('logged-in page shows the username and reply forms', str_contains($page['body'], 'Signed in as <strong>' . $username . '</strong>') && str_contains($page['body'], 'Post comment') && str_contains($page['body'], '<summary>Reply</summary>'));

echo "\n== Authenticated posting ==\n";
$tok = $c->token('/discussions/1');
$before = $commentCount();
$r = $c->post('/discussions/1/comments/7/replies', ['csrf_token' => $tok, 'body' => 'My depth five reply', 'user_id' => '1']);
check('reply to comment 7 redirects back to the discussion', $r['status'] === 303 && str_contains((string) $r['location'], '/discussions/1#comment-'));
$new = $pdo->prepare('SELECT id, user_id, parent_id, discussion_id FROM comments WHERE body = :b');
$new->execute(['b' => 'My depth five reply']);
$reply = $new->fetch();
check('reply row: parent 7, same discussion', $reply && (int) $reply['parent_id'] === 7 && (int) $reply['discussion_id'] === 1);
check('author comes from the session, not from a forged user_id field', $reply && (int) $reply['user_id'] === $userId);
$page = $c->get('/discussions/1');
check('depth-5 reply shows its author and "Depth 5"', (bool) preg_match('/id="comment-' . $reply['id'] . '".*?<strong class="author">' . $username . '<\/strong>.*?Depth 5</s', $page['body']));
$r = $c->post('/discussions/1/comments', ['csrf_token' => $tok, 'body' => 'My second top-level comment']);
$top = $pdo->prepare('SELECT id, user_id, parent_id FROM comments WHERE body = :b');
$top->execute(['b' => 'My second top-level comment']);
$topRow = $top->fetch();
check('top-level comment saved with NULL parent and the session user', $topRow && $topRow['parent_id'] === null && (int) $topRow['user_id'] === $userId);
$page = $c->get('/discussions/1');
check('top-level comment is shown at "Depth 0" after the seed threads', (bool) preg_match('/id="comment-' . $topRow['id'] . '".*?Depth 0</s', $page['body']));
check('two comments were added in total', $commentCount() === $before + 2);

echo "\n== Rejected writes ==\n";
$before = $commentCount();
check('invalid CSRF token is 403', $c->post('/discussions/1/comments', ['csrf_token' => 'forged', 'body' => 'x'])['status'] === 403);
check('missing CSRF token is 403', $c->post('/discussions/1/comments', ['body' => 'x'])['status'] === 403);
check('parent from another discussion is 422', $c->post('/discussions/1/comments/8/replies', ['csrf_token' => $tok, 'body' => 'cross'])['status'] === 422);
check('nonexistent parent is 422', $c->post('/discussions/1/comments/99999/replies', ['csrf_token' => $tok, 'body' => 'ghost'])['status'] === 422);
check('missing discussion is 404', $c->post('/discussions/99999/comments', ['csrf_token' => $tok, 'body' => 'x'])['status'] === 404);
check('malformed discussion ID is 400', $c->post('/discussions/abc/comments', ['csrf_token' => $tok, 'body' => 'x'])['status'] === 400);
check('malformed parent ID is 400', $c->post('/discussions/1/comments/abc/replies', ['csrf_token' => $tok, 'body' => 'x'])['status'] === 400);
$empty = $c->post('/discussions/1/comments', ['csrf_token' => $tok, 'body' => "   \n "]);
$long = $c->post('/discussions/1/comments', ['csrf_token' => $tok, 'body' => str_repeat('a', $config['forum']['max_body_length'] + 1)]);
$ctrl = $c->post('/discussions/1/comments', ['csrf_token' => $tok, 'body' => "bad\x00char"]);
check('empty, overlong and control-character bodies are bounced back', $empty['status'] === 303 && $long['status'] === 303 && $ctrl['status'] === 303);
check('none of the rejected requests created a row', $commentCount() === $before);

echo "\n== Escaping ==\n";
$xss = '<script>alert("xss")</script><img src=x onerror=alert(1)>';
$c->post('/discussions/1/comments', ['csrf_token' => $tok, 'body' => $xss]);
$page = $c->get('/discussions/1');
check('HTML in a comment is escaped on output', str_contains($page['body'], '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;&lt;img src=x onerror=alert(1)&gt;'));
check('no raw <script>alert or <img src=x appears', !str_contains($page['body'], '<script>alert') && !str_contains($page['body'], '<img src=x'));

echo "\n== Depth limit ==\n";
// Build a chain in discussion 2 down to depth ($maxDepth - 1), owned by the test user so cleanup removes it.
$insert = $pdo->prepare('INSERT INTO comments (discussion_id, parent_id, user_id, body) VALUES (2, :p, :u, :b)');
$parent = 9; // depth 1 in discussion 2
for ($d = 2; $d <= $maxDepth - 1; $d++) {
    $insert->execute(['p' => $parent, 'u' => $userId, 'b' => "chain $d"]);
    $parent = (int) $pdo->lastInsertId();
}
$tok = $c->token('/discussions/2');
$atLimit = $c->post("/discussions/2/comments/$parent/replies", ['csrf_token' => $tok, 'body' => "depth $maxDepth"]);
check("a reply at depth $maxDepth is accepted", $atLimit['status'] === 303);
$q = $pdo->prepare('SELECT id FROM comments WHERE body = :b');
$q->execute(['b' => "depth $maxDepth"]);
$limitId = (int) $q->fetchColumn();
$beyond = $c->post("/discussions/2/comments/$limitId/replies", ['csrf_token' => $tok, 'body' => 'too deep']);
check('a reply deeper than the limit is 422', $beyond['status'] === 422);
$deepPage = $c->get('/discussions/2');
check('the deep thread renders, labels stay accurate, and the limit notice shows',
    $deepPage['status'] === 200 && str_contains($deepPage['body'], 'Depth ' . $maxDepth . '<') && str_contains($deepPage['body'], 'Reply depth limit reached.'));
check('indentation is capped at 8 while labels keep counting',
    str_contains($deepPage['body'], 'indent-8') && !str_contains($deepPage['body'], 'indent-9') && str_contains($deepPage['body'], 'replies flat'));

echo "\n== Logout ==\n";
$oldSid = $c->cookies['forum_sid'];
$tok = $c->token('/discussions/1');
check('logout without a CSRF token is 403', $c->post('/logout')['status'] === 403);
$out = $c->post('/logout', ['csrf_token' => $tok]);
check('logout redirects', $out['status'] === 303);
check('after logout the page shows the login links', str_contains($c->get('/discussions/1')['body'], 'Log in to reply'));
$before = $commentCount();
$tok2 = $c->token('/login');
$g1 = $c->post('/discussions/1/comments', ['csrf_token' => $tok2, 'body' => 'after logout, via the form']);
$stale = new Client($baseUrl);
$stale->cookies['forum_sid'] = $oldSid;
$g2 = $stale->post('/discussions/1/comments/7/replies', ['csrf_token' => $tok, 'body' => 'replayed old session']);
check('posting after logout is refused (form-style and with the old session cookie)', $g1['status'] === 303 && $g2['status'] === 303);
check('no row was created after logout', $commentCount() === $before);

echo PHP_EOL . ($failures === 0 ? 'All end-to-end checks passed.' : "$failures check(s) failed.") . PHP_EOL;
exit($failures === 0 ? 0 : 1);
