-- Outbox of CarLot records for the core (docs/adr/0003). Append-only: the core
-- keeps its read offset in its own schema (ingest_offsets) and never writes here.
CREATE TABLE IF NOT EXISTS ingest_lots (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    run_id        BIGINT UNSIGNED NULL,
    source        VARCHAR(32)     NOT NULL,
    source_id     VARCHAR(100)    NOT NULL,
    contract      VARCHAR(20)     NOT NULL,
    payload       JSON            NOT NULL,
    content_hash  CHAR(64)        NOT NULL,
    created_at    TIMESTAMP(3)    NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY idx_ingest_lot (source, source_id),
    KEY idx_ingest_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
