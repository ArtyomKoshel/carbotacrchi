Use the `mysql-carbot` MCP to inspect the taxonomy anomaly queue.

```sql
SELECT 
  taq.id,
  taq.source,
  taq.grade_tail,
  taq.seen_count,
  taq.sample_lot_id,
  taq.created_at
FROM taxonomy_anomaly_queue taq
WHERE taq.resolved_at IS NULL
ORDER BY taq.seen_count DESC
LIMIT 30;
```

Also show breakdown by source:
```sql
SELECT source, COUNT(*) as count, SUM(seen_count) as total_occurrences
FROM taxonomy_anomaly_queue
WHERE resolved_at IS NULL
GROUP BY source;
```

If the user passed an argument (`$ARGUMENTS`), filter by source or search grade_tail:
```sql
SELECT * FROM taxonomy_anomaly_queue
WHERE resolved_at IS NULL
  AND (source = '$ARGUMENTS' OR grade_tail LIKE '%$ARGUMENTS%')
ORDER BY seen_count DESC
LIMIT 20;
```

Present a table of top anomalies with counts. Suggest next steps: run `taxonomy:bootstrap-rules` to auto-create rules for high-count anomalies, or `taxonomy:classify-anomalies` for AI classification.
