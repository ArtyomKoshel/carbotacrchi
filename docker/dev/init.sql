-- First start of the dev MySQL only (empty volume). Local throw-away passwords only.
-- Core schema + collector schema + least-privilege collector user, as on prod (docs/refactor/cutover.md, step 1).
CREATE DATABASE IF NOT EXISTS carbot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS collector CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'collector'@'%' IDENTIFIED BY 'collector-local';
GRANT ALL PRIVILEGES ON collector.* TO 'collector'@'%';
-- Grants on carbot.parse_filters / parse_jobs: grants.sql, applied by web once the core migrations created them.

-- Test databases: laravel/phpunit*.xml, laravel/.env.testing, collector and parser tests, CI — all at 127.0.0.1:3318, carbot/carbot.
CREATE DATABASE IF NOT EXISTS carbot_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS carbot_test_release CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS collector_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS collector_test_release CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'carbot'@'%' IDENTIFIED BY 'carbot';
GRANT ALL PRIVILEGES ON carbot_test.* TO 'carbot'@'%';
GRANT ALL PRIVILEGES ON carbot_test_release.* TO 'carbot'@'%';
GRANT ALL PRIVILEGES ON collector_test.* TO 'carbot'@'%';
GRANT ALL PRIVILEGES ON collector_test_release.* TO 'carbot'@'%';
FLUSH PRIVILEGES;
