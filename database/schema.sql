-- Nested Forum Lab: schema and fictional seed data.
-- Works on MySQL 8 and MariaDB 10.5+.
-- WARNING: this script drops and recreates the three forum tables.
--
-- Run:  mysql -u root -p < database/schema.sql

CREATE DATABASE IF NOT EXISTS forum_lab
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE forum_lab;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS comments;
DROP TABLE IF EXISTS discussions;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(30)  NOT NULL,
  email         VARCHAR(254) NOT NULL,
  password_hash VARCHAR(255) NULL,            -- NULL = cannot log in (seed users)
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,  -- stored as UTC
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),     -- case-insensitive via collation
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE discussions (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  title      VARCHAR(200) NOT NULL,
  body       TEXT         NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_discussions_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE comments (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  discussion_id INT UNSIGNED NOT NULL,
  parent_id     INT UNSIGNED NULL,             -- NULL = top-level (depth 0)
  user_id       INT UNSIGNED NOT NULL,
  body          TEXT         NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  -- Target of the composite foreign key below.
  UNIQUE KEY uq_comments_discussion_id (discussion_id, id),
  CONSTRAINT fk_comments_discussion FOREIGN KEY (discussion_id) REFERENCES discussions (id),
  CONSTRAINT fk_comments_user       FOREIGN KEY (user_id)       REFERENCES users (id),
  -- A parent must exist AND belong to the same discussion. With parent_id NULL the
  -- constraint is not checked (MATCH SIMPLE). It does not stop every possible cycle;
  -- the application never updates parent_id and only links to existing rows.
  CONSTRAINT fk_comments_parent_same_discussion
    FOREIGN KEY (discussion_id, parent_id) REFERENCES comments (discussion_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Fictional seed data. Seed users have NULL password hashes, so they can never
-- log in. Register your own account to post.
-- ---------------------------------------------------------------------------
INSERT INTO users (id, username, email, password_hash, created_at) VALUES
  (1, 'Alice', 'alice@example.test', NULL, '2026-01-10 09:00:00'),
  (2, 'Ben',   'ben@example.test',   NULL, '2026-01-10 09:05:00'),
  (3, 'Cara',  'cara@example.test',  NULL, '2026-01-10 09:10:00'),
  (4, 'Dee',   'dee@example.test',   NULL, '2026-01-10 09:15:00'),
  (5, 'Eli',   'eli@example.test',   NULL, '2026-01-10 09:20:00');

INSERT INTO discussions (id, user_id, title, body, created_at) VALUES
  (1, 1, 'How do I learn PHP?',          'Share your learning tips.',                      '2026-01-11 08:00:00'),
  (2, 3, 'Which editor should I use?',   'Tell us what you write your PHP in and why.',    '2026-01-12 08:00:00');

-- Discussion 1: the lab's sample tree. Parents are inserted before their children.
INSERT INTO comments (id, discussion_id, parent_id, user_id, body, created_at) VALUES
  (1, 1, NULL, 1, 'Start with the PHP manual.',        '2026-01-11 08:10:00'),
  (2, 1, 1,    2, 'Try the examples as you read.',     '2026-01-11 08:20:00'),
  (3, 1, 2,    3, 'Build a small project too.',        '2026-01-11 08:30:00'),
  (4, 1, 1,    4, 'Which manual section first?',       '2026-01-11 08:40:00'),
  (5, 1, NULL, 5, 'Practice SQL as well.',             '2026-01-11 08:50:00'),
  (6, 1, 3,    2, 'Start with a forum.',               '2026-01-11 09:00:00'),
  (7, 1, 6,    5, 'Add replies next.',                 '2026-01-11 09:10:00');

-- Discussion 2: used to test that a reply cannot point at another discussion's comment.
INSERT INTO comments (id, discussion_id, parent_id, user_id, body, created_at) VALUES
  (8, 2, NULL, 4, 'Any editor with PHP syntax highlighting works.', '2026-01-12 08:15:00'),
  (9, 2, 8,    1, 'An editor that shows errors as you type helps.', '2026-01-12 08:25:00');
