# CarBot — Project Context for Claude

Telegram Mini App + Bot for searching cars on Korean auction platforms (Encar, KBChacha).  
Company uses it to find cars for clients instead of manually browsing auction sites.

**Stack:** Laravel 11 · PHP 8.2 · Python 3.11 · MySQL 8 · Docker  
**Deploy:** Railway (remote). Migrations run on Railway — never run `migrate:fresh` locally against prod DB.

---

## Documentation

Full docs are in `docs/` — read them before touching unfamiliar areas:

| File | Topic |
|------|-------|
| [docs/01-overview.md](docs/01-overview.md) | Architecture diagram, data flows, key principles |
| [docs/02-structure.md](docs/02-structure.md) | Directory tree, module roles |
| [docs/03-database.md](docs/03-database.md) | All tables, `lots` schema, ER diagram |
| [docs/04-parser.md](docs/04-parser.md) | Python parser phases, CarLot, LotRepository, scheduler |
| [docs/05-catalog.md](docs/05-catalog.md) | Seed data, GradeExtractorService, 6 extraction stages |
| [docs/06-normalization.md](docs/06-normalization.md) | `lots:normalize-encar-taxonomy` command, 4 stages |
| [docs/07-taxonomy.md](docs/07-taxonomy.md) | Rules, actions, anomaly queue, AI classification |
| [docs/08-api.md](docs/08-api.md) | REST endpoints, admin panel, providers |
| [docs/09-bot.md](docs/09-bot.md) | Text search, AI prompt, lot cards |
| [docs/10-filters.md](docs/10-filters.md) | PRE/POST filter phases, lot exclusion rules |
| [docs/11-commands.md](docs/11-commands.md) | All artisan commands with options and examples |
| [docs/12-field-map.md](docs/12-field-map.md) | Field reference: API paths, coverage, normalization |

@docs/01-overview.md

@docs/03-database.md

@docs/11-commands.md

---

## MCP: Production Database

The `mysql-carbot` MCP server connects directly to the production Railway MySQL.  
Use it for inspection and debugging — never for destructive operations without confirmation.

**🔒 Правило:** Claude может делать SELECT-запросы для анализа и диагностики.
UPDATE / DELETE / INSERT / DDL (ALTER, DROP, TRUNCATE) — **только по явному запросу пользователя.**

```sql
-- Example: check a lot
SELECT id, source, make, model_en, year, price, trim, generation, fuel FROM lots WHERE id = '?';

-- Anomaly queue size
SELECT source, COUNT(*) FROM taxonomy_anomaly_queue WHERE resolved_at IS NULL GROUP BY source;
```

---

## Key Conventions

- **Non-destructive normalization**: only fills NULL fields, never overwrites existing values
- `raw_data['pre_norm']` stores the original state before first normalization
- Parser filters: PRE (before DB write) and POST (after inspection) — different levels
- Taxonomy rules override catalog data (higher priority)
- `is_active=0` means filtered out by `parse_filters`, not deleted
