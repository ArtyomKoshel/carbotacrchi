import json
import logging
import time as _time
from datetime import datetime

import pymysql
from pymysql.cursors import DictCursor

from config import Config

logger = logging.getLogger(__name__)


class DBWriter:
    def __init__(self):
        self._conn: pymysql.Connection | None = None
        self._total_upserted = 0
        self._total_db_time = 0.0

    def _get_conn(self) -> pymysql.Connection:
        if self._conn is None or not self._conn.open:
            logger.info(f"[DB] Connecting to {Config.DB_HOST}:{Config.DB_PORT}/{Config.DB_DATABASE}")
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
            elapsed = _time.monotonic() - t0
            logger.info(f"[DB] Connected in {elapsed:.2f}s")
        return self._conn

    def upsert_lots(self, lots: list[dict]) -> int:
        if not lots:
            return 0

        conn = self._get_conn()
        sql = """
            INSERT INTO lots (
                id, source, make, model, year, price, mileage, vin,
                body_type, transmission, fuel, drive_type,
                damage, secondary_damage, title, document,
                location, color, `trim`,
                engine_volume, cylinders, has_keys,
                retail_value, repair_cost,
                image_url, lot_url, raw_data,
                fetched_at, expires_at, price_krw, is_active, parsed_at,
                created_at, updated_at
            ) VALUES (
                %(id)s, %(source)s, %(make)s, %(model)s, %(year)s, %(price)s, %(mileage)s, %(vin)s,
                %(body_type)s, %(transmission)s, %(fuel)s, %(drive_type)s,
                %(damage)s, %(secondary_damage)s, %(title)s, %(document)s,
                %(location)s, %(color)s, %(trim)s,
                %(engine_volume)s, %(cylinders)s, %(has_keys)s,
                %(retail_value)s, %(repair_cost)s,
                %(image_url)s, %(lot_url)s, %(raw_data)s,
                %(fetched_at)s, %(expires_at)s, %(price_krw)s, 1, %(parsed_at)s,
                NOW(), NOW()
            ) ON DUPLICATE KEY UPDATE
                make=VALUES(make), model=VALUES(model), year=VALUES(year),
                price=VALUES(price), mileage=VALUES(mileage),
                body_type=VALUES(body_type), transmission=VALUES(transmission),
                fuel=VALUES(fuel), drive_type=VALUES(drive_type),
                location=VALUES(location), color=VALUES(color), `trim`=VALUES(`trim`),
                engine_volume=VALUES(engine_volume),
                image_url=VALUES(image_url), lot_url=VALUES(lot_url),
                raw_data=VALUES(raw_data),
                price_krw=VALUES(price_krw), is_active=1,
                parsed_at=VALUES(parsed_at), updated_at=NOW()
        """

        now = datetime.utcnow().strftime("%Y-%m-%d %H:%M:%S")
        rows = []
        for lot in lots:
            raw_json = lot.get("raw_data")
            if isinstance(raw_json, dict):
                raw_json = json.dumps(raw_json, ensure_ascii=False)

            rows.append({
                "id": lot["id"],
                "source": lot["source"],
                "make": lot.get("make"),
                "model": lot.get("model"),
                "year": lot.get("year"),
                "price": lot.get("price"),
                "mileage": lot.get("mileage", 0),
                "vin": lot.get("vin"),
                "body_type": lot.get("body_type"),
                "transmission": lot.get("transmission"),
                "fuel": lot.get("fuel"),
                "drive_type": lot.get("drive_type"),
                "damage": lot.get("damage"),
                "secondary_damage": lot.get("secondary_damage"),
                "title": lot.get("title"),
                "document": lot.get("document"),
                "location": lot.get("location"),
                "color": lot.get("color"),
                "trim": lot.get("trim"),
                "engine_volume": lot.get("engine_volume"),
                "cylinders": lot.get("cylinders"),
                "has_keys": lot.get("has_keys"),
                "retail_value": lot.get("retail_value"),
                "repair_cost": lot.get("repair_cost"),
                "image_url": lot.get("image_url"),
                "lot_url": lot.get("lot_url"),
                "raw_data": raw_json,
                "fetched_at": now,
                "expires_at": None,
                "price_krw": lot.get("price_krw"),
                "parsed_at": now,
            })

        try:
            t0 = _time.monotonic()
            with conn.cursor() as cursor:
                cursor.executemany(sql, rows)
            conn.commit()
            elapsed = _time.monotonic() - t0
            self._total_upserted += len(rows)
            self._total_db_time += elapsed

            sources = {}
            for r in rows:
                m = r.get("make", "?")
                sources[m] = sources.get(m, 0) + 1
            breakdown = ", ".join(f"{k}:{v}" for k, v in sorted(sources.items(), key=lambda x: -x[1])[:5])

            logger.info(f"[DB] Upserted {len(rows)} lots in {elapsed:.2f}s ({breakdown})")
            return len(rows)
        except Exception as e:
            conn.rollback()
            logger.error(f"[DB] Upsert FAILED for {len(rows)} lots: {type(e).__name__}: {e}")
            raise

    def mark_stale(self, source: str, active_ids: set[str]) -> int:
        """Mark lots not in active_ids as inactive."""
        if not active_ids:
            return 0

        conn = self._get_conn()
        placeholders = ",".join(["%s"] * len(active_ids))
        sql = f"""
            UPDATE lots SET is_active = 0, updated_at = NOW()
            WHERE source = %s AND is_active = 1 AND id NOT IN ({placeholders})
        """
        try:
            t0 = _time.monotonic()
            with conn.cursor() as cursor:
                cursor.execute(sql, [source] + list(active_ids))
            conn.commit()
            affected = cursor.rowcount
            elapsed = _time.monotonic() - t0
            logger.info(f"[DB] Marked {affected} lots as stale for '{source}' in {elapsed:.2f}s "
                         f"(checked against {len(active_ids)} active IDs)")
            return affected
        except Exception as e:
            conn.rollback()
            logger.error(f"[DB] mark_stale FAILED for '{source}': {type(e).__name__}: {e}")
            return 0

    def get_stats(self) -> dict:
        return {
            "total_upserted": self._total_upserted,
            "total_db_time": round(self._total_db_time, 2),
        }

    def close(self):
        stats = self.get_stats()
        logger.info(f"[DB] Session stats: {stats['total_upserted']} lots upserted, "
                     f"{stats['total_db_time']}s total DB time")
        if self._conn and self._conn.open:
            self._conn.close()
            self._conn = None
            logger.debug("[DB] Connection closed")
