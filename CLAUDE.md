# CarBot — Project Context for Claude

Telegram Mini App + Bot for searching cars on Korean auction platforms (Encar, KBChacha).  
Company uses it to find cars for clients instead of manually browsing auction sites.

**Stack:** Laravel 13 · PHP 8.4 · Python 3.11 · MySQL 8 · Docker  
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
| [docs/08-api.md](docs/08-api.md) | REST endpoints, admin panel, providers |
| [docs/09-bot.md](docs/09-bot.md) | Text search, AI prompt, lot cards |
| [docs/10-filters.md](docs/10-filters.md) | PRE/POST filter phases, lot exclusion rules |
| [docs/11-commands.md](docs/11-commands.md) | All artisan commands with options and examples |
| [docs/12-field-map.md](docs/12-field-map.md) | Field reference: API paths, coverage |
| [docs/13-catalog.md](docs/13-catalog.md) | Reference catalog: make → model → generation → modification → complectation, Encar matching, nothing assumed |

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
SELECT id, source, make, model, badge, `trim`, year, price, ref_model_id, ref_generation_id FROM lots WHERE id = '?';

-- Variants the reference catalog could not place, by lots
SELECT status, COUNT(*), SUM(lots) FROM ref_variants GROUP BY status;
```

---

## Key Conventions

- Make / model / generation / modification / complectation names come only from the reference catalog (`ref_*`, docs/13-catalog.md); there are no AI translations in the data
- Nothing guesses engine, drive, fuel or generation: only what Encar states; unknown stays empty and shows as «не указано» in the filters
- Encar's own fields (`make`, `model`, `badge`, `trim`, `trim_en`) are stored as Encar sends them; a non-null new value replaces the stored one
- Parser filters: PRE (before DB write) and POST (after inspection) — different levels
- `is_active=0` means filtered out by `parse_filters` or delisted (missed by the latest successful full run). `lots:lifecycle` deletes lots missed by the last 5 successful full runs, keeping a row in `lots_archive`. Counted in runs, never days (docs/04-parser.md#жизненный-цикл-лота)
