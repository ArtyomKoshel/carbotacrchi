-- A resumed run reads back the lots it already wrote (MySqlIngestSink.resume).
ALTER TABLE ingest_lots ADD KEY idx_ingest_run (run_id, id);
