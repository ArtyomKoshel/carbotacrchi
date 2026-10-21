-- Resumable runs: where a run stopped (a deploy, a crash) and what it logged.
CREATE TABLE IF NOT EXISTS run_checkpoints (
    job_id      BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    source      VARCHAR(32)     NOT NULL,
    state       JSON            NOT NULL,
    updated_at  DATETIME(3)     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS run_log (
    id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    job_id   BIGINT UNSIGNED NOT NULL,
    at       DATETIME(3)     NOT NULL,
    level    VARCHAR(10)     NOT NULL,
    message  TEXT            NOT NULL,
    KEY idx_run_log_job (job_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
