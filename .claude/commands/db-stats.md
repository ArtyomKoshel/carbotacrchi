Use the `mysql-carbot` MCP to fetch a quick status overview of the production database.

Run these queries in order:

```sql
-- Lots overview
SELECT 
  source,
  COUNT(*) as total,
  SUM(is_active) as active,
  COUNT(*) - SUM(is_active) as filtered,
  SUM(CASE WHEN model_en IS NOT NULL THEN 1 ELSE 0 END) as with_model_en,
  SUM(CASE WHEN generation IS NOT NULL THEN 1 ELSE 0 END) as with_generation,
  SUM(CASE WHEN trim IS NOT NULL THEN 1 ELSE 0 END) as with_trim
FROM lots
GROUP BY source;
```

```sql
-- Anomaly queue
SELECT source, COUNT(*) as pending
FROM taxonomy_anomaly_queue
WHERE resolved_at IS NULL
GROUP BY source;
```

```sql
-- Recent parse jobs
SELECT source, status, lots_fetched, lots_inserted, lots_updated, started_at, finished_at
FROM parse_jobs
ORDER BY started_at DESC
LIMIT 5;
```

Present as a formatted summary with sections: **Lots**, **Anomaly Queue**, **Recent Parser Runs**.
