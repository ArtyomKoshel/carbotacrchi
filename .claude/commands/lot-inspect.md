Use the `mysql-carbot` MCP to inspect a lot from the production database.

The user will provide a lot ID (e.g. `40897028` or `k-123456`). Run this query:

```sql
SELECT 
  id, source, make, model, model_en, year, price, mileage,
  trim, generation, variant, package,
  fuel, drive_type, body_type, engine_volume, cylinders, seat_count, transmission, color,
  has_accident, flood_history, total_loss_history, owners_count, insurance_count,
  lien_status, seizure_status, is_active, parsed_at, expires_at
FROM lots 
WHERE id = '$ARGUMENTS'
LIMIT 1;
```

Then also fetch photos count:
```sql
SELECT COUNT(*) as photo_count FROM lot_photos WHERE lot_id = '$ARGUMENTS';
```

Present results as a clean summary grouped by: identification, normalized fields, specs, history/legal, meta.

If no lot found, say so clearly.
