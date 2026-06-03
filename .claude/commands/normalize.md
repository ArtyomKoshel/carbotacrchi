Guide the user through running the normalization pipeline for Encar lots.

## Steps

**1. Dry-run first (safe, no changes):**
```bash
docker compose exec php php artisan lots:normalize-encar-taxonomy --limit=500
```

**2. Check what would change — show the user the summary output.**

**3. Apply changes:**
```bash
docker compose exec php php artisan lots:normalize-encar-taxonomy --limit=500 --apply
```

**4. Check anomaly queue after normalization:**
```bash
docker compose exec php php artisan taxonomy:bootstrap-rules --source=encar --min-seen=5
```

**5. For unresolved anomalies — AI classification:**
```bash
docker compose exec php php artisan taxonomy:classify-anomalies --source=encar --batch=20 --limit=100
```

If the user provides arguments (`$ARGUMENTS`), adapt the `--limit` or `--source` flags accordingly.

Read `docs/06-normalization.md` for the 4-stage normalization flow if asked for details.
