-- What the collector last fetched about each lot's history (record + inspection),
-- rejected lots included, so it is not fetched again every run (docs/adr/0004).
CREATE TABLE IF NOT EXISTS lot_history (
    source          VARCHAR(32)  NOT NULL,
    lot_id          VARCHAR(100) NOT NULL,
    checked_at      DATETIME     NOT NULL,
    condition_tags  JSON         NOT NULL,
    fields          JSON         NOT NULL,
    PRIMARY KEY (source, lot_id),
    KEY idx_history_checked (source, checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
