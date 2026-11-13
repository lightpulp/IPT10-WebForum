# Nested Forum (PHP, MySQL, PDO)

A small forum where replies nest as a tree. Anyone can read; only a logged-in user can post.
Built for the "Visualizing Nested Forum Replies" lab.

## Stack and why

| Concern | Choice | Notes |
|---|---|---|
| Language | PHP 8.2+ with `pdo_mysql` | |
| Database | MySQL 8 or MariaDB 10.5+ | |
| Router | [bramus/router](https://github.com/bramus/router) | one front controller, GET and POST routes |
| Templates | [mustache/mustache](https://github.com/bobthecow/mustache.php) | `{{ }}` escapes HTML; no `{{{ }}}` is used |
| Data access | **PDO** with prepared statements | Used instead of Eloquent to keep dependencies small. SQL lives only in `app/Models`. |

## Install

```bash
composer install                       # installs the two libraries and creates composer.lock (commit it)
```

Create the database, tables and seed data (this **drops and recreates** `users`, `discussions`, `comments`):

```bash
mysql -u root -p < database/schema.sql
```

Create a least-privilege database user (choose your own password; never commit it):

```sql
CREATE USER 'forum_app'@'localhost' IDENTIFIED BY 'choose-a-password';
GRANT SELECT, INSERT, UPDATE, DELETE ON forum_lab.* TO 'forum_app'@'localhost';
```

Tell the app how to connect through environment variables:

```bash
export DB_HOST=127.0.0.1 DB_PORT=3306 DB_NAME=forum_lab DB_USER=forum_app
export DB_PASS='choose-a-password'
```

(Alternatively create `config/local.php`, which is git-ignored, returning `['db' => ['pass' => '...']]`.)

## Run locally

```bash
composer serve        # php -S 127.0.0.1:8000 -t public public/index.php
```

Open <http://127.0.0.1:8000/discussions/1>. The seed users (Alice, Ben, Cara, Dee, Eli) have no
password and cannot log in: **register your own account** at `/register`, then log in.

### Routes

| Method | URL | Purpose |
|---|---|---|
| GET | `/`, `/discussions` | list discussions |
| GET | `/discussions/{id}` | one discussion with its reply tree |
| GET/POST | `/register` | create an account |
| GET/POST | `/login` | log in |
| POST | `/logout` | log out |
| POST | `/discussions/{id}/comments` | post a top-level comment (login required) |
| POST | `/discussions/{id}/comments/{parentId}/replies` | reply to a comment (login required) |

Malformed ID (`/discussions/abc`, `0`, `-1`, `1e3`) answers **400**. A well-formed ID with no row answers **404**.
Unknown paths answer 404.

### Production rewrites (explanation only)

Point the web server's document root at `public/` and send every request that is not a real file to `index.php`.
Apache: `RewriteCond %{REQUEST_FILENAME} !-f` then `RewriteRule ^ index.php [L]`.
Nginx: `try_files $uri /index.php$is_args$args;`. Serve over HTTPS so the session cookie gets the `Secure` flag.

## Project layout

```
public/index.php          front controller (the only entry point)
public/assets/            style.css, collapse.js (optional challenge), default-avatar.svg
config/config.php         settings (reads DB_* environment variables)
config/routes.php         URL -> controller method table
app/Controllers/          HTTP handling: DiscussionController, CommentController, AuthController
app/Models/               PDO data access: User, Discussion, Comment (all SQL is here)
app/Support/CommentTree   groups rows by parent and builds the nested tree with depths
app/Core/                 Auth, Csrf, Session, Flash, View, Database, Request, Http, Url
app/Views/                Mustache templates; partials/comment.mustache is recursive
database/schema.sql       schema and fictional seed data
tests/                    tree_test.php (unit) and http_test.php (end to end)
```

## How a request flows

`GET /discussions/1`

1. **Router** (`public/index.php` + `config/routes.php`) matches the URL and calls `DiscussionController::show('1')`.
2. **Controller** validates the ID (`400` if malformed), asks the **model** for the discussion (`404` if missing),
   then loads its comments, groups them by `parent_id` and builds the nested tree (`CommentTree`).
3. **Models** run prepared PDO queries and return plain arrays. They know nothing about HTML.
4. **View** (`discussion_show.mustache` + recursive `partials/comment.mustache`) turns the prepared data into HTML.

A write (`POST .../replies`) runs the same path, with these checks in order: logged in? -> CSRF token valid? ->
IDs valid? -> discussion exists? -> parent exists **in this discussion**? -> body valid? -> depth within limit? ->
insert using the **session** user ID -> redirect (Post/Redirect/Get).

## Data model

`comments.discussion_id` says which discussion a comment belongs to. `comments.parent_id` says which comment it
answers (`NULL` = top-level). A composite foreign key `(discussion_id, parent_id) -> comments(discussion_id, id)`
makes the database refuse a parent from a different discussion. It needs the unique key `(discussion_id, id)`.
When `parent_id` is `NULL` the key is not checked.

**Depth is calculated, not stored.** Top-level comments are depth 0; each reply adds 1. In the sample data comment 7
is displayed at depth 4. Counting the discussion as the root, its graph depth is 5.

**Limits**

| Limit | Value | Where |
|---|---|---|
| Indentation stops growing | displayed depth 8 (labels keep counting) | `visual_indent_cap` |
| Deepest allowed new reply | displayed depth 25 | `max_reply_depth`, enforced in `CommentController` |
| Renderer traversal | 1000 comments, depth 100, each comment visited once | `CommentTree::build` |

**Cycles.** A new row can only point at a row that already exists, and no code ever updates `parent_id`,
so a new comment can never become its own ancestor. The renderer also tracks visited IDs. Comments that cannot be
reached from the top level (a damaged, disconnected cycle) are simply not drawn. The renderer's guard is not a
database-wide integrity check.

## Security notes

- **Parameter binding:** every query uses PDO prepared statements with `ATTR_EMULATE_PREPARES = false`, so values
  travel to the server separately from the SQL text. No user value is ever concatenated into SQL.
- **HTML escaping:** Mustache `{{ }}` output is escaped with `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE)`.
  Comment text is stored exactly as typed and escaped only when displayed. `<script>` shows up as visible text.
- **Passwords:** `password_hash` (bcrypt) and `password_verify`. A `NULL` or empty hash never authenticates. Login
  errors are generic, and an unknown account still costs one hash check.
- **Sessions:** ID regenerated on login and logout; cookie is `HttpOnly`, `SameSite=Lax`, and `Secure` over HTTPS;
  strict mode on.
- **CSRF:** a session-backed random token is in every form (register, login, logout, comment, reply) and compared
  with `hash_equals`.
- **Authorization:** checked in the controller on every write, not only by hiding the form. `user_id` is taken from
  the session; a forged `user_id` field is ignored.
- **Headers:** a strict Content-Security-Policy (no inline script or style), `nosniff`, no framing.
- **Secrets:** database credentials come from environment variables; `.env`, `config/local.php` and `vendor/` are git-ignored.

## Optional challenge: collapse and expand

`public/assets/collapse.js` adds a real `<button type="button">` to every comment that has replies. It sets
`aria-controls` (the branch's unique `replies-{id}`), toggles `aria-expanded`, switches the label between
"Hide replies" and "Show replies", and uses the `hidden` attribute. Branches start expanded, and the buttons are
created by the script, so with JavaScript off all replies show and no dead controls appear. Comment text is never
read or inserted by the script.

## Tests

```bash
composer test         # unit checks for the tree builder; no database or server needed
composer test:http    # end-to-end checks; needs the DB loaded, DB_* set, and `composer serve` running
```

`tests/http_test.php` creates a throw-away user and deletes everything it created. It covers: public reading and
tree order, depth labels, 400/404 IDs, guest writes (form and direct POST) creating no rows, registration
validation and duplicate rejection, hashed passwords, wrong password, NULL-hash seed accounts, session
regeneration, cookie flags, depth-5 reply and second top-level comment with correct authors, forged `user_id`,
invalid and missing CSRF tokens, cross-discussion parent, missing discussion, HTML/script comment escaping, the
reply depth limit with the indentation cap, and logout (including replaying the old session cookie).

### Manual checks (for your screenshots)

1. Register, log in, open `/discussions/1`.
2. Reply to Eli's comment (depth 4) to create a **depth-5** reply, then add a second top-level comment.
3. Log out. Try to post from the page (the forms are gone) and with
   `curl -i -X POST -d 'body=hi' http://127.0.0.1:8000/discussions/1/comments` (redirects to login, no row created).
4. Try `/discussions/abc` (400), `/discussions/999` (404), a form with a wrong `csrf_token` (403),
   and a comment containing `<script>alert(1)</script>` (shown as text).
5. Confirm in SQL: `SELECT id, parent_id, user_id, body FROM comments ORDER BY id;`

## Submission checklist

- [ ] Latest commits pushed; repository link opens for the instructor
- [ ] `composer.json` and `composer.lock` committed; `vendor/` and credentials are not
- [ ] Google Doc: name, course/section, title, date, repository link, request-flow explanation,
      screenshots with your own commentary, development reflection
- [ ] Doc sharing allows the instructor to view; no passwords, tokens or real personal data in screenshots

## Known limitations

Loads one discussion's comments into memory and renders recursively, which suits lab-sized data. A production
forum would need pagination or lazy loading, login rate limiting, account recovery, moderation, logging and
monitoring. Avatar upload and Gravatar are not implemented (optional in the lab); every user shows the default avatar.

## No access to Packagist?

If `composer install` times out (for example on a school network), use the bundled copy of this project that already
contains `vendor/` and skip the install step. `vendor/` is still git-ignored, so do not commit it; your instructor
can run `composer install` from your repository.
