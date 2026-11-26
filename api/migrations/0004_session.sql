-- Sign-in sessions and sign-in state (PHP sessions) live in the database, not in files: Render's free plan loses its
-- disk at every restart and sleep, which signed everyone out. Exabytes keeps files, but one store for both hosts.
CREATE TABLE php_session (
    id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    data MEDIUMBLOB NOT NULL,
    updated_at INT UNSIGNED NOT NULL, -- unix time of the last write; old rows are removed by PHP's session gc
    KEY ix_php_session_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
