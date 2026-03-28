from __future__ import annotations

import json
import logging
import time as _time
from datetime import datetime

import pymysql
from pymysql.cursors import DictCursor

from config import Config
from models import CarLot

logger = logging.getLogger(__name__)


class LotRepository:
    def __init__(self):
        self._conn: pymysql.Connection | None = None

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
            logger.info(f"[DB] Connected in {_time.monotonic() - t0:.2f}s")
        return self._conn

    def upsert_batch(self, lots: list[CarLot]) -> int:
        if not lots:
            return 0

        conn = self._get_conn()
        now = datetime.utcnow().strftime("%Y-%m-%d %H:%M:%S")

        sql = """
            INSERT INTO lots (
                id, source, make, model, year, price, mileage, vin,
                body_type, transmission, fuel, drive_type,
                damage, secondary_damage, title, document,
                location, color, seat_color, `trim`, `options`,
                engine_volume, cylinders, has_keys,
                retail_value, repair_cost,
                image_url, lot_url, raw_data,
                fetched_at, price_krw, is_active, parsed_at,
                plate_number, registration_date,
                lien_status, seizure_status, tax_paid,
                accident_status, total_loss_history, flood_history,
                owners_count, insurance_count, mileage_grade,
                new_car_price_ratio, ai_price_min, ai_price_max,
                dealer_name, dealer_company, dealer_location,
                dealer_phone, dealer_description,
                created_at, updated_at
            ) VALUES (
                %(id)s, %(source)s, %(make)s, %(model)s, %(year)s, %(price)s, %(mileage)s, %(vin)s,
                %(body_type)s, %(transmission)s, %(fuel)s, %(drive_type)s,
                %(damage)s, %(secondary_damage)s, %(title)s, %(document)s,
                %(location)s, %(color)s, %(seat_color)s, %(trim)s, %(options)s,
                %(engine_volume)s, %(cylinders)s, %(has_keys)s,
                %(retail_value)s, %(repair_cost)s,
                %(image_url)s, %(lot_url)s, %(raw_data)s,
                %(now)s, %(price_krw)s, 1, %(now)s,
                %(plate_number)s, %(registration_date)s,
                %(lien_status)s, %(seizure_status)s, %(tax_paid)s,
                %(accident_status)s, %(total_loss_history)s, %(flood_history)s,
                %(owners_count)s, %(insurance_count)s, %(mileage_grade)s,
                %(new_car_price_ratio)s, %(ai_price_min)s, %(ai_price_max)s,
                %(dealer_name)s, %(dealer_company)s, %(dealer_location)s,
                %(dealer_phone)s, %(dealer_description)s,
                %(now)s, %(now)s
            ) ON DUPLICATE KEY UPDATE
                price=VALUES(price), mileage=VALUES(mileage),
                make=VALUES(make), model=VALUES(model), year=VALUES(year),
                body_type=COALESCE(VALUES(body_type), body_type),
                transmission=COALESCE(VALUES(transmission), transmission),
                fuel=COALESCE(VALUES(fuel), fuel),
                drive_type=COALESCE(VALUES(drive_type), drive_type),
                engine_volume=COALESCE(VALUES(engine_volume), engine_volume),
                color=COALESCE(VALUES(color), color),
                seat_color=COALESCE(VALUES(seat_color), seat_color),
                location=VALUES(location), `trim`=COALESCE(VALUES(`trim`), `trim`),
                `options`=COALESCE(VALUES(`options`), `options`),
                image_url=COALESCE(VALUES(image_url), image_url),
                lot_url=VALUES(lot_url),
                raw_data=VALUES(raw_data),
                price_krw=VALUES(price_krw), is_active=1,
                plate_number=COALESCE(VALUES(plate_number), plate_number),
                registration_date=COALESCE(VALUES(registration_date), registration_date),
                lien_status=COALESCE(VALUES(lien_status), lien_status),
                seizure_status=COALESCE(VALUES(seizure_status), seizure_status),
                tax_paid=COALESCE(VALUES(tax_paid), tax_paid),
                accident_status=COALESCE(VALUES(accident_status), accident_status),
                total_loss_history=COALESCE(VALUES(total_loss_history), total_loss_history),
                flood_history=COALESCE(VALUES(flood_history), flood_history),
                owners_count=COALESCE(VALUES(owners_count), owners_count),
                insurance_count=COALESCE(VALUES(insurance_count), insurance_count),
                mileage_grade=COALESCE(VALUES(mileage_grade), mileage_grade),
                new_car_price_ratio=COALESCE(VALUES(new_car_price_ratio), new_car_price_ratio),
                ai_price_min=COALESCE(VALUES(ai_price_min), ai_price_min),
                ai_price_max=COALESCE(VALUES(ai_price_max), ai_price_max),
                dealer_name=COALESCE(VALUES(dealer_name), dealer_name),
                dealer_company=COALESCE(VALUES(dealer_company), dealer_company),
                dealer_location=COALESCE(VALUES(dealer_location), dealer_location),
                dealer_phone=COALESCE(VALUES(dealer_phone), dealer_phone),
                dealer_description=COALESCE(VALUES(dealer_description), dealer_description),
                parsed_at=VALUES(parsed_at), updated_at=VALUES(updated_at)
        """

        rows = []
        for lot in lots:
            row = lot.to_db_row()
            row["now"] = now
            rows.append(row)

        try:
            t0 = _time.monotonic()
            with conn.cursor() as cursor:
                cursor.executemany(sql, rows)
            conn.commit()
            elapsed = _time.monotonic() - t0
            logger.info(f"[DB] Upserted {len(rows)} lots in {elapsed:.2f}s")
            return len(rows)
        except Exception as e:
            conn.rollback()
            logger.error(f"[DB] Upsert FAILED for {len(rows)} lots: {type(e).__name__}: {e}")
            raise

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

    def mark_inactive(self, source: str, active_ids: set[str], grace_hours: int = 24) -> int:
        if not active_ids:
            return 0

        conn = self._get_conn()
        placeholders = ",".join(["%s"] * len(active_ids))
        sql = f"""
            UPDATE lots SET is_active = 0, updated_at = NOW()
            WHERE source = %s
              AND is_active = 1
              AND id NOT IN ({placeholders})
              AND parsed_at < DATE_SUB(NOW(), INTERVAL %s HOUR)
        """

        try:
            t0 = _time.monotonic()
            with conn.cursor() as cursor:
                cursor.execute(sql, [source] + list(active_ids) + [grace_hours])
            conn.commit()
            affected = cursor.rowcount
            elapsed = _time.monotonic() - t0
            logger.info(f"[DB] Marked {affected} lots inactive for '{source}' "
                         f"(grace={grace_hours}h) in {elapsed:.2f}s")
            return affected
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

    def close(self):
        if self._conn and self._conn.open:
            self._conn.close()
            self._conn = None
            logger.debug("[DB] Connection closed")
