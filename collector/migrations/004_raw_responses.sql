-- Raw source responses per lot (docs/adr/0005): the latest version of each kind
-- (list item, detail, record, inspection, sellingpoint), zlib-compressed. A
-- mapping fix is applied by replaying these, without asking the platform again.
CREATE TABLE IF NOT EXISTS raw_responses (
    source        VARCHAR(32)  NOT NULL,
    lot_id        VARCHAR(100) NOT NULL,
    kind          VARCHAR(20)  NOT NULL,
    content_hash  CHAR(64)     NOT NULL,
    body          MEDIUMBLOB   NOT NULL,
    fetched_at    DATETIME     NOT NULL,
    PRIMARY KEY (source, lot_id, kind),
    KEY idx_raw_fetched (source, fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
