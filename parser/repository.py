from __future__ import annotations

import json
import logging
import re
import time as _time
from datetime import datetime

import pymysql
from pymysql.cursors import DictCursor

from config import Config
from models import CarLot, InspectionRecord

logger = logging.getLogger(__name__)


class LotRepository:
    # Lazy-initialized shared FilterEngine. Rules are reloaded from DB every
    # _FILTER_RELOAD_INTERVAL seconds so that admin changes take effect without
    # restarting the parser.
    _FILTER_RELOAD_INTERVAL = 60.0
    _filter_engine = None
    _filter_loaded_at: float = 0.0

    def __init__(self):
        self._conn: pymysql.Connection | None = None

    def _get_filter_engine(self):
        """Return a FilterEngine with rules from DB (cached, refreshed every 60s)."""
        now = _time.monotonic()
        if (LotRepository._filter_engine is None or
                now - LotRepository._filter_loaded_at > self._FILTER_RELOAD_INTERVAL):
            try:
                from filters import FilterEngine, load_rules
                rules = load_rules(conn=self._get_conn())
                LotRepository._filter_engine = FilterEngine(rules)
                LotRepository._filter_loaded_at = now
                logger.info(f"[filter] engine (re)loaded: {len(rules.rules)} rules active")
            except Exception as e:
                logger.warning(f"[filter] cannot load rules: {type(e).__name__}: {e}")
                if LotRepository._filter_engine is None:
                    # Hard fallback: empty engine — nothing is filtered.
                    from filters import FilterEngine
                    LotRepository._filter_engine = FilterEngine([])
        return LotRepository._filter_engine

    def _apply_filters(self, lots: list[CarLot], stats: dict | None) -> list[CarLot]:
        """Run FilterEngine over lots. Returns kept lots; mutates stats['filtered'].

        Side-effect: any lot filtered out that is already active in DB is
        immediately marked is_active=0 so it disappears from the listings
        (otherwise it would live forever because the normal delist code
        thinks it's still "seen").
        """
        if not lots:
            return lots
        engine = self._get_filter_engine()
        kept: list[CarLot] = []
        skipped_ids: list[str] = []
        filtered_counts: dict[str, int] = {}
        for lot in lots:
            result = engine.evaluate(lot)
            if result.should_skip:
                for rule in result.matched_rules:
                    if rule.action == "skip":
                        filtered_counts[rule.name] = filtered_counts.get(rule.name, 0) + 1
                skipped_ids.append(lot.id)
                continue
            if result.should_mark_inactive:
                lot.raw_data["_filter_mark_inactive"] = True
            kept.append(lot)

        if skipped_ids:
            self._deactivate_existing(skipped_ids, reason="filter")

        if stats is not None and filtered_counts:
            stats["filtered"] = stats.get("filtered", 0) + sum(filtered_counts.values())
            stats.setdefault("filter_rules", {})
            for name, n in filtered_counts.items():
                stats["filter_rules"][name] = stats["filter_rules"].get(name, 0) + n
        if filtered_counts:
            rule_summary = ", ".join(f"{n}={c}" for n, c in sorted(filtered_counts.items(), key=lambda kv: -kv[1]))
            logger.info(
                f"[filter] skipped {sum(filtered_counts.values())}/{len(lots)} lots "
                f"({rule_summary})"
            )
        return kept

    def _deactivate_existing(self, lot_ids: list[str], reason: str = "filter") -> int:
        """Mark is_active=0 for any of given ids that are currently active.

        Returns count of rows affected. Used when filter rules newly exclude
        lots that were previously stored as active.
        """
        if not lot_ids:
            return 0
        conn = self._get_conn()
        placeholders = ",".join(["%s"] * len(lot_ids))
        sql = (
            f"UPDATE lots SET is_active = 0, updated_at = NOW() "
            f"WHERE id IN ({placeholders}) AND is_active = 1"
        )
        try:
            with conn.cursor() as cur:
                cur.execute(sql, list(lot_ids))
                affected = cur.rowcount
            conn.commit()
            if affected > 0:
                logger.info(
                    f"[DB] {reason}: deactivated {affected} previously active lots"
                )
                # Also record history entries so the admin panel shows the transition
                self._insert_lot_changes([
                    {
                        "lot_id": lid,
                        "source": "",
                        "event": f"deactivated_{reason}",
                        "changes": {"is_active": {"old": True, "new": False}},
                    }
                    for lid in lot_ids[:affected]  # only log affected ones (can't distinguish which)
                ])
            return affected
        except Exception as e:
            conn.rollback()
            logger.warning(f"[DB] _deactivate_existing failed: {type(e).__name__}: {e}")
            return 0

    def _get_conn(self) -> pymysql.Connection:
        if self._conn is None or not self._conn.open:
            logger.debug(f"[DB] Connecting to {Config.DB_HOST}:{Config.DB_PORT}/{Config.DB_DATABASE}")
            t0 = _time.monotonic()
            self._conn = pymysql.connect(
                host=Config.DB_HOST,
                port=Config.DB_PORT,
                user=Config.DB_USERNAME,
                password=Config.DB_PASSWORD,
                database=Config.DB_DATABASE,
                charset="utf8mb4",
                cursorclass=DictCursor,
                autocommit=False,
            )
            logger.debug(f"[DB] Connected in {_time.monotonic() - t0:.2f}s")
        return self._conn

    # Fields compared on every upsert to detect meaningful changes.
    # Derived from the FieldRegistry SSOT (parser/fields/registry.py) — see
    # FieldSpec.tracked. `is_active` is appended manually because it is a
    # repository-managed column, not a CarLot attribute.
    @classmethod
    def _get_tracked_fields(cls) -> tuple[str, ...]:
        try:
            from fields import TRACKED_FIELDS as _registry_tracked
            names = tuple(f.name for f in _registry_tracked) + ("is_active",)
            return names
        except Exception:
            # Fallback — registry unavailable (should not normally happen).
            return (
                "price", "mileage",
                "has_accident", "flood_history", "total_loss_history",
                "lien_status", "seizure_status",
                "owners_count", "insurance_count",
                "trim", "color", "options",
                "sell_type",
                "is_active",
            )

    # Cached at class-load; re-read on server restart (registry is static code).
    _TRACKED_FIELDS: tuple[str, ...] = ()  # populated below

    def _fetch_tracked(self, lot_ids: list[str]) -> dict[str, dict]:
        """Return {lot_id: {field: value}} for all _TRACKED_FIELDS of existing lots."""
        if not lot_ids:
            return {}
        conn = self._get_conn()
        fields = ", ".join(f"`{f}`" for f in self._TRACKED_FIELDS)
        placeholders = ", ".join(["%s"] * len(lot_ids))
        sql = f"SELECT id, {fields} FROM lots WHERE id IN ({placeholders})"
        try:
            with conn.cursor() as cursor:
                cursor.execute(sql, lot_ids)
                return {row["id"]: dict(row) for row in cursor.fetchall()}
        except Exception as e:
            logger.warning(f"[DB] _fetch_tracked failed: {e}")
            return {}

    def _insert_lot_changes(self, changes_rows: list[dict]) -> None:
        """Bulk-insert rows into lot_changes. Each row: lot_id, source, event, changes (dict)."""
        if not changes_rows:
            return
        conn = self._get_conn()
        now = datetime.utcnow().strftime("%Y-%m-%d %H:%M:%S")
        sql = """
            INSERT INTO lot_changes (lot_id, source, event, changes, recorded_at)
            VALUES (%(lot_id)s, %(source)s, %(event)s, %(changes)s, %(now)s)
        """
        rows = [
            {**r, "changes": json.dumps(r["changes"], ensure_ascii=False, default=str), "now": now}
            for r in changes_rows
        ]
        try:
            with conn.cursor() as cursor:
                cursor.executemany(sql, rows)
            conn.commit()
            logger.debug(f"[DB] Recorded {len(rows)} lot_changes entries")
        except Exception as e:
            conn.rollback()
            logger.warning(f"[DB] _insert_lot_changes failed: {type(e).__name__}: {e}")

    def upsert_batch(self, lots: list[CarLot], stats: dict | None = None) -> int:
        if not lots:
            return 0

        # ── Pre-upsert filter ────────────────────────────────────────────────
        # Apply declarative rules (sell_type/mileage/year/price/...) before
        # any DB write. Lots marked "skip" are dropped here; "mark_inactive"
        # flag is handled below via is_active column override.
        lots = self._apply_filters(lots, stats)
        if not lots:
            return 0

        conn = self._get_conn()
        now = datetime.utcnow().strftime("%Y-%m-%d %H:%M:%S")

        # Read current tracked field values BEFORE overwriting them
        old_states = self._fetch_tracked([lot.id for lot in lots])

        sql = """
            INSERT INTO lots (
                id, source, make, model, year, price, mileage, vin,
                body_type, transmission, fuel, drive_type,
                cylinders, engine_volume, fuel_economy,
                damage, secondary_damage,
                lien_status, seizure_status, tax_paid,
                has_accident, flood_history, total_loss_history,
                owners_count, insurance_count, has_keys, mileage_grade,
                title, document,
                location, color, seat_color, `trim`, `options`, paid_options, warranty_text,
                retail_value, repair_cost,
                new_car_price_ratio, registration_year_month,
                image_url, lot_url, raw_data,
                fetched_at, is_active, parsed_at,
                plate_number, registration_date,
                dealer_name, dealer_company, dealer_location,
                dealer_phone, dealer_description,
                sell_type, sell_type_raw,
                created_at, updated_at
            ) VALUES (
                %(id)s, %(source)s, %(make)s, %(model)s, %(year)s, %(price)s, %(mileage)s, %(vin)s,
                %(body_type)s, %(transmission)s, %(fuel)s, %(drive_type)s,
                %(cylinders)s, %(engine_volume)s, %(fuel_economy)s,
                %(damage)s, %(secondary_damage)s,
                %(lien_status)s, %(seizure_status)s, %(tax_paid)s,
                %(has_accident)s, %(flood_history)s, %(total_loss_history)s,
                %(owners_count)s, %(insurance_count)s, %(has_keys)s, %(mileage_grade)s,
                %(title)s, %(document)s,
                %(location)s, %(color)s, %(seat_color)s, %(trim)s, %(options)s, %(paid_options)s, %(warranty_text)s,
                %(retail_value)s, %(repair_cost)s,
                %(new_car_price_ratio)s, %(registration_year_month)s,
                %(image_url)s, %(lot_url)s, %(raw_data)s,
                %(now)s, %(is_active)s, %(now)s,
                %(plate_number)s, %(registration_date)s,
                %(dealer_name)s, %(dealer_company)s, %(dealer_location)s,
                %(dealer_phone)s, %(dealer_description)s,
                %(sell_type)s, %(sell_type_raw)s,
                %(now)s, %(now)s
            ) ON DUPLICATE KEY UPDATE
                price=VALUES(price), mileage=VALUES(mileage),
                make=VALUES(make), model=VALUES(model), year=VALUES(year),
                body_type=COALESCE(VALUES(body_type), body_type),
                transmission=COALESCE(VALUES(transmission), transmission),
                fuel=COALESCE(VALUES(fuel), fuel),
                drive_type=COALESCE(VALUES(drive_type), drive_type),
                cylinders=COALESCE(VALUES(cylinders), cylinders),
                engine_volume=COALESCE(VALUES(engine_volume), engine_volume),
                fuel_economy=COALESCE(VALUES(fuel_economy), fuel_economy),
                color=COALESCE(VALUES(color), color),
                seat_color=COALESCE(VALUES(seat_color), seat_color),
                location=VALUES(location), `trim`=COALESCE(VALUES(`trim`), `trim`),
                `options`=COALESCE(VALUES(`options`), `options`),
                paid_options=COALESCE(VALUES(paid_options), paid_options),
                warranty_text=COALESCE(VALUES(warranty_text), warranty_text),
                image_url=COALESCE(VALUES(image_url), image_url),
                lot_url=VALUES(lot_url),
                raw_data=VALUES(raw_data),
                is_active=VALUES(is_active),
                lien_status=COALESCE(VALUES(lien_status), lien_status),
                seizure_status=COALESCE(VALUES(seizure_status), seizure_status),
                tax_paid=COALESCE(VALUES(tax_paid), tax_paid),
                has_accident=COALESCE(VALUES(has_accident), has_accident),
                flood_history=COALESCE(VALUES(flood_history), flood_history),
                total_loss_history=COALESCE(VALUES(total_loss_history), total_loss_history),
                owners_count=COALESCE(VALUES(owners_count), owners_count),
                insurance_count=COALESCE(VALUES(insurance_count), insurance_count),
                mileage_grade=COALESCE(VALUES(mileage_grade), mileage_grade),
                retail_value=COALESCE(VALUES(retail_value), retail_value),
                repair_cost=COALESCE(VALUES(repair_cost), repair_cost),
                new_car_price_ratio=COALESCE(VALUES(new_car_price_ratio), new_car_price_ratio),
                registration_year_month=COALESCE(VALUES(registration_year_month), registration_year_month),
                plate_number=COALESCE(VALUES(plate_number), plate_number),
                registration_date=COALESCE(VALUES(registration_date), registration_date),
                dealer_name=COALESCE(VALUES(dealer_name), dealer_name),
                dealer_company=COALESCE(VALUES(dealer_company), dealer_company),
                dealer_location=COALESCE(VALUES(dealer_location), dealer_location),
                dealer_phone=COALESCE(VALUES(dealer_phone), dealer_phone),
                dealer_description=COALESCE(VALUES(dealer_description), dealer_description),
                sell_type=COALESCE(VALUES(sell_type), sell_type),
                sell_type_raw=COALESCE(VALUES(sell_type_raw), sell_type_raw),
                parsed_at=VALUES(parsed_at), updated_at=VALUES(updated_at)
        """

        rows = []
        for lot in lots:
            row = lot.to_db_row()
            row["now"] = now
            # Respect filter-engine mark_inactive flag (default: active)
            row["is_active"] = 0 if lot.raw_data.get("_filter_mark_inactive") else 1
            rows.append(row)

        try:
            t0 = _time.monotonic()
            with conn.cursor() as cursor:
                cursor.executemany(sql, rows)
            conn.commit()
            elapsed = _time.monotonic() - t0
            logger.debug(f"[DB] Upserted {len(rows)} lots in {elapsed:.2f}s")
        except Exception as e:
            conn.rollback()
            logger.error(f"[DB] Batch upsert FAILED ({len(rows)} lots): {type(e).__name__}: {e}")
            m = re.search(r"at row (\d+)", str(e))
            col_m = re.search(r"column '([^']+)'", str(e))
            if m:
                row_idx = int(m.group(1)) - 1
                if 0 <= row_idx < len(rows):
                    bad = rows[row_idx]
                    col = col_m.group(1) if col_m else "?"
                    logger.error(
                        f"[DB] Offending row {row_idx + 1}: "
                        f"id={bad.get('id')} source={bad.get('source')} "
                        f"make={bad.get('make')} model={bad.get('model')} year={bad.get('year')} "
                        f"bad_column={col} bad_value={bad.get(col, '<not in row>')!r}"
                    )
            # Fallback: retry one-by-one so one bad lot doesn't lose the whole page
            logger.warning(f"[DB] Retrying {len(rows)} lots one-by-one...")
            saved = 0
            for i, row in enumerate(rows):
                try:
                    with conn.cursor() as cursor:
                        cursor.execute(sql, row)
                    conn.commit()
                    saved += 1
                except Exception as e2:
                    conn.rollback()
                    col2 = re.search(r"column '([^']+)'", str(e2))
                    col_name = col2.group(1) if col2 else "?"
                    logger.error(
                        f"[DB] Lot {i+1}/{len(rows)} SKIPPED: "
                        f"id={row.get('id')} {row.get('make')} {row.get('model')} {row.get('year')} "
                        f"— {col_name}={row.get(col_name, '?')!r} — {e2}"
                    )
                    lots[i].raw_data["_db_skip"] = True
            logger.warning(f"[DB] One-by-one retry: {saved}/{len(rows)} saved, {len(rows)-saved} skipped")
            # Remove skipped lots from change-detection below
            lots = [l for l in lots if not l.raw_data.get("_db_skip")]
            rows = [_lot_to_row(l) for l in lots]

        # Detect field changes for existing lots and persist to lot_changes
        changes_to_insert = []
        for lot in lots:
            old = old_states.get(lot.id)
            if old is None:
                continue  # new lot — no history yet
            new_row = lot.to_db_row()
            diff = {}
            for field in self._TRACKED_FIELDS:
                old_val = old.get(field)
                new_val = new_row.get(field)
                # Normalise options JSON string for comparison
                if field == "options":
                    try:
                        old_val = json.loads(old_val) if isinstance(old_val, str) else old_val
                        new_val = json.loads(new_val) if isinstance(new_val, str) else new_val
                    except Exception:
                        pass
                if old_val != new_val and new_val is not None:
                    diff[field] = {"old": old_val, "new": new_val}
            if diff:
                # Detect relist: is_active changed from 0 to 1
                event = "update"
                if "is_active" in diff and diff["is_active"].get("old") in (0, False):
                    event = "relisted"
                changes_to_insert.append({
                    "lot_id": lot.id,
                    "source": lot.source,
                    "event": event,
                    "changes": diff,
                })
        if changes_to_insert:
            self._insert_lot_changes(changes_to_insert)
            if stats is not None:
                stats["updated"] = stats.get("updated", 0) + len(changes_to_insert)

        # Auto-upsert photos from the transit field `lot.photos` into the
        # `lot_photos` table. Parsers populate `lot.photos` during enrichment;
        # storing them here keeps the raw_data column free of bulky arrays.
        for lot in lots:
            if lot.photos:
                try:
                    self.upsert_photos(lot.id, lot.photos)
                except Exception as e:
                    logger.warning(f"[DB] upsert_photos auto {lot.id}: {e}")

        return len(rows)

    def get_existing_ids(self, source: str) -> set[str]:
        conn = self._get_conn()
        try:
            with conn.cursor() as cursor:
                cursor.execute(
                    "SELECT id FROM lots WHERE source = %s AND is_active = 1",
                    (source,),
                )
                return {row["id"] for row in cursor.fetchall()}
        except Exception as e:
            logger.error(f"[DB] get_existing_ids failed: {e}")
            return set()

    def count_active(self, source: str) -> int:
        conn = self._get_conn()
        try:
            with conn.cursor() as cursor:
                cursor.execute(
                    "SELECT COUNT(*) AS cnt FROM lots WHERE source = %s AND is_active = 1",
                    (source,),
                )
                return cursor.fetchone()["cnt"]
        except Exception as e:
            logger.error(f"[DB] count_active failed: {e}")
            return -1

    def mark_inactive(self, source: str, active_ids: set[str], grace_hours: int = 1) -> int:
        """Delist active lots not seen this run (with grace period to avoid double-run issues)."""
        if not active_ids:
            return 0

        conn = self._get_conn()
        placeholders = ",".join(["%s"] * len(active_ids))
        params = [source] + list(active_ids) + [grace_hours]

        select_sql = f"""
            SELECT id FROM lots
            WHERE source = %s
              AND is_active = 1
              AND id NOT IN ({placeholders})
              AND parsed_at < DATE_SUB(NOW(), INTERVAL %s HOUR)
        """
        update_sql = f"""
            UPDATE lots SET is_active = 0, updated_at = NOW()
            WHERE source = %s
              AND is_active = 1
              AND id NOT IN ({placeholders})
              AND parsed_at < DATE_SUB(NOW(), INTERVAL %s HOUR)
        """

        try:
            t0 = _time.monotonic()
            with conn.cursor() as cursor:
                cursor.execute(select_sql, params)
                delisted_ids = [row["id"] for row in cursor.fetchall()]
                cursor.execute(update_sql, params)
            conn.commit()
            elapsed = _time.monotonic() - t0
            logger.info(
                f"[DB] Delist for '{source}': {len(delisted_ids)} lots delisted "
                f"(grace={grace_hours}h) in {elapsed:.2f}s"
            )
            if delisted_ids:
                self._insert_lot_changes([
                    {"lot_id": lid, "source": source, "event": "delisted",
                     "changes": {"is_active": {"old": True, "new": False}}}
                    for lid in delisted_ids
                ])
            return len(delisted_ids)
        except Exception as e:
            conn.rollback()
            logger.error(f"[DB] mark_inactive FAILED: {type(e).__name__}: {e}")
            return 0

    def count_by_source(self, source: str) -> dict:
        conn = self._get_conn()
        try:
            with conn.cursor() as cursor:
                cursor.execute(
                    "SELECT is_active, COUNT(*) as cnt FROM lots WHERE source = %s GROUP BY is_active",
                    (source,),
                )
                result = {"active": 0, "inactive": 0}
                for row in cursor.fetchall():
                    if row["is_active"]:
                        result["active"] = row["cnt"]
                    else:
                        result["inactive"] = row["cnt"]
                return result
        except Exception as e:
            logger.error(f"[DB] count_by_source failed: {e}")
            return {"active": 0, "inactive": 0}

    def upsert_photos(self, lot_id: str, photos: list[str]) -> int:
        """Insert photos into lot_photos. Skips duplicates by (lot_id, position)."""
        if not photos:
            return 0
        conn = self._get_conn()
        sql = """
            INSERT IGNORE INTO lot_photos (lot_id, url, position)
            VALUES (%s, %s, %s)
        """
        rows = [(lot_id, url, pos) for pos, url in enumerate(photos)]
        try:
            with conn.cursor() as cursor:
                cursor.executemany(sql, rows)
            conn.commit()
            return len(rows)
        except Exception as e:
            conn.rollback()
            logger.warning(f"[DB] upsert_photos failed for {lot_id}: {type(e).__name__}: {e}")
            return 0

    def upsert_inspection(self, record: InspectionRecord) -> None:
        row = record.to_db_row()
        conn = self._get_conn()
        now = datetime.utcnow().strftime("%Y-%m-%d %H:%M:%S")
        sql = """
            INSERT INTO lot_inspections (
                lot_id, source, cert_no, inspection_date,
                valid_from, valid_until, report_url,
                first_registration, inspection_mileage, insurance_fee,
                has_accident, has_outer_damage, has_flood, has_fire, has_tuning,
                accident_detail, outer_detail, details,
                created_at, updated_at
            ) VALUES (
                %(lot_id)s, %(source)s, %(cert_no)s, %(inspection_date)s,
                %(valid_from)s, %(valid_until)s, %(report_url)s,
                %(first_registration)s, %(inspection_mileage)s, %(insurance_fee)s,
                %(has_accident)s, %(has_outer_damage)s, %(has_flood)s, %(has_fire)s, %(has_tuning)s,
                %(accident_detail)s, %(outer_detail)s, %(details)s,
                %(now)s, %(now)s
            ) ON DUPLICATE KEY UPDATE
                source=VALUES(source),
                cert_no=COALESCE(VALUES(cert_no), cert_no),
                inspection_date=COALESCE(VALUES(inspection_date), inspection_date),
                valid_from=COALESCE(VALUES(valid_from), valid_from),
                valid_until=COALESCE(VALUES(valid_until), valid_until),
                report_url=COALESCE(VALUES(report_url), report_url),
                first_registration=COALESCE(VALUES(first_registration), first_registration),
                inspection_mileage=COALESCE(VALUES(inspection_mileage), inspection_mileage),
                insurance_fee=COALESCE(VALUES(insurance_fee), insurance_fee),
                has_accident=COALESCE(VALUES(has_accident), has_accident),
                has_outer_damage=COALESCE(VALUES(has_outer_damage), has_outer_damage),
                has_flood=COALESCE(VALUES(has_flood), has_flood),
                has_fire=COALESCE(VALUES(has_fire), has_fire),
                has_tuning=COALESCE(VALUES(has_tuning), has_tuning),
                accident_detail=COALESCE(VALUES(accident_detail), accident_detail),
                outer_detail=COALESCE(VALUES(outer_detail), outer_detail),
                details=VALUES(details),
                updated_at=%(now)s
        """
        row["now"] = now
        try:
            with conn.cursor() as cursor:
                cursor.execute(sql, row)
            conn.commit()
            logger.debug(f"[DB] Upserted inspection for {record.lot_id}")
        except Exception as e:
            conn.rollback()
            logger.error(f"[DB] upsert_inspection FAILED for {record.lot_id}: {type(e).__name__}: {e}")
            raise

    def get_lots_by_source(
        self, source: str, limit: int | None = None, ids: list[str] | None = None
    ) -> list[CarLot]:
        conn = self._get_conn()
        params: list = [source]
        sql = "SELECT * FROM lots WHERE source = %s"
        if ids:
            placeholders = ",".join(["%s"] * len(ids))
            sql += f" AND id IN ({placeholders})"
            params.extend(ids)
        else:
            sql += " AND is_active = 1"
        sql += " ORDER BY updated_at ASC"
        if limit:
            sql += f" LIMIT {int(limit)}"
        try:
            with conn.cursor() as cursor:
                cursor.execute(sql, params)
                rows = cursor.fetchall()
            lots = []
            for row in rows:
                try:
                    raw = json.loads(row["raw_data"]) if row["raw_data"] else {}
                    opts = json.loads(row["options"]) if row.get("options") else None
                    paid = json.loads(row["paid_options"]) if row.get("paid_options") else None
                    lot = CarLot(
                        id=row["id"], source=row["source"],
                        make=row["make"] or "", model=row["model"] or "",
                        year=row["year"] or 0, price=row["price"] or 0,
                        mileage=row["mileage"] or 0,
                        registration_year_month=row.get("registration_year_month"),
                        location=row.get("location"),
                        lot_url=row.get("lot_url") or "",
                        image_url=row.get("image_url"),
                        options=opts, paid_options=paid,
                        raw_data=raw,
                        vin=row.get("vin"),
                        fuel=row.get("fuel"),
                        body_type=row.get("body_type"),
                        transmission=row.get("transmission"),
                        color=row.get("color"),
                        seat_color=row.get("seat_color"),
                        trim=row.get("trim"),
                        engine_volume=row.get("engine_volume"),
                        fuel_economy=row.get("fuel_economy"),
                        cylinders=row.get("cylinders"),
                        drive_type=row.get("drive_type"),
                        plate_number=row.get("plate_number"),
                        registration_date=row.get("registration_date"),
                        lien_status=row.get("lien_status"),
                        seizure_status=row.get("seizure_status"),
                        has_accident=row.get("has_accident"),
                        flood_history=row.get("flood_history"),
                        total_loss_history=row.get("total_loss_history"),
                        owners_count=row.get("owners_count"),
                        insurance_count=row.get("insurance_count"),
                        mileage_grade=row.get("mileage_grade"),
                        tax_paid=row.get("tax_paid"),
                        damage=row.get("damage"),
                        secondary_damage=row.get("secondary_damage"),
                        title=row.get("title") or "Clean",
                        has_keys=row.get("has_keys"),
                        retail_value=row.get("retail_value"),
                        repair_cost=row.get("repair_cost"),
                        warranty_text=row.get("warranty_text"),
                        dealer_name=row.get("dealer_name"),
                        dealer_company=row.get("dealer_company"),
                        dealer_location=row.get("dealer_location"),
                        dealer_phone=row.get("dealer_phone"),
                        dealer_description=row.get("dealer_description"),
                        new_car_price_ratio=row.get("new_car_price_ratio"),
                    )
                    lots.append(lot)
                except Exception as e:
                    logger.warning(f"[DB] Skipping lot {row.get('id')}: {e}")
            logger.info(f"[DB] Loaded {len(lots)} lots for source='{source}'")
            return lots
        except Exception as e:
            logger.error(f"[DB] get_lots_by_source failed: {e}")
            return []

    def close(self):
        if self._conn and self._conn.open:
            self._conn.close()
            self._conn = None
            logger.debug("[DB] Connection closed")


# Populate tracked fields once, after class is fully defined, from the SSOT.
LotRepository._TRACKED_FIELDS = LotRepository._get_tracked_fields()
