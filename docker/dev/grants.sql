GRANT SELECT ON carbot.parse_filters TO 'collector'@'%';
GRANT SELECT, UPDATE (status, progress, result, updated_at) ON carbot.parse_jobs TO 'collector'@'%';
FLUSH PRIVILEGES;
