-- Same layout as prod: core schema + collector schema + least-privilege collector user
-- (docs/refactor/cutover.md, step 1). Local throw-away passwords only.
CREATE DATABASE IF NOT EXISTS carbot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS collector CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'collector'@'%' IDENTIFIED BY 'collector-local';
GRANT ALL PRIVILEGES ON collector.* TO 'collector'@'%';
FLUSH PRIVILEGES;
-- Grants on carbot.parse_filters / parse_jobs are given by grants.sql once the core migrations created them.
