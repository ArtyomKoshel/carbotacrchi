import argparse
import logging
import os
import sys
import time
from logging.handlers import RotatingFileHandler

from dotenv import load_dotenv

load_dotenv()

from config import Config
from repository import LotRepository
from parsers.kbcha import KBChaParser
from scheduler import start_scheduler

logger = logging.getLogger("parser")


def _setup_logging(debug: bool = False) -> None:
    level = logging.DEBUG if debug else getattr(logging, Config.LOG_LEVEL, logging.INFO)
    fmt = logging.Formatter(
        "%(asctime)s [%(levelname)s] %(name)s: %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S",
    )

    root = logging.getLogger()
    root.setLevel(level)
    root.handlers.clear()

    ch = logging.StreamHandler(sys.stdout)
    ch.setFormatter(fmt)
    root.addHandler(ch)

    if Config.LOG_FILE:
        os.makedirs(os.path.dirname(Config.LOG_FILE), exist_ok=True)
        fh = RotatingFileHandler(
            Config.LOG_FILE, maxBytes=10 * 1024 * 1024, backupCount=5, encoding="utf-8"
        )
        fh.setFormatter(fmt)
        root.addHandler(fh)


def _parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Carbot parser")
    parser.add_argument("--once", action="store_true",
                        help="Run once and exit (no scheduler)")
    parser.add_argument("--pages", type=int, default=None,
                        help="Max pages per maker (overrides KBCHA_MAX_PAGES)")
    parser.add_argument("--maker", type=str, default=None,
                        help="Parse only this maker (Korean name, e.g. 현대, 기아, BMW)")
    parser.add_argument("--debug", action="store_true",
                        help="Enable DEBUG logging (shows missing fields, inspection details)")
    parser.add_argument("--reenrich", action="store_true",
                        help="Re-fetch detail pages for lots already in DB (no list page fetching)")
    parser.add_argument("--limit", type=int, default=None,
                        help="Max lots to re-enrich (used with --reenrich)")
    return parser.parse_args()


def wait_for_db(max_retries: int = 30, delay: float = 2.0) -> None:
    import pymysql
    for attempt in range(1, max_retries + 1):
        try:
            conn = pymysql.connect(
                host=Config.DB_HOST, port=Config.DB_PORT,
                user=Config.DB_USERNAME, password=Config.DB_PASSWORD,
                database=Config.DB_DATABASE, connect_timeout=5,
            )
            conn.close()
            logger.info("MySQL is ready")
            return
        except Exception:
            logger.info(f"Waiting for MySQL... ({attempt}/{max_retries})")
            time.sleep(delay)
    logger.error("MySQL not available, starting anyway")


def run_once(pages: int | None = None, maker: str | None = None) -> None:
    repo = LotRepository()
    try:
        if Config.KBCHA_ENABLED:
            parser = KBChaParser(repo)
            count = parser.run(max_pages=pages, maker_filter=maker)
            logger.info(f"KBChacha: {count} lots imported")
    finally:
        repo.close()


def run_reenrich(limit: int | None = None) -> None:
    repo = LotRepository()
    try:
        if Config.KBCHA_ENABLED:
            parser = KBChaParser(repo)
            count = parser.run_reenrich(limit=limit)
            logger.info(f"KBChacha re-enrich: {count} lots updated")
    finally:
        repo.close()


def main() -> None:
    args = _parse_args()
    _setup_logging(debug=args.debug)

    logger.info("Parser service starting...")
    logger.info(f"  KBChacha: {'enabled' if Config.KBCHA_ENABLED else 'disabled'}")
    logger.info(f"  DB: {Config.DB_HOST}:{Config.DB_PORT}/{Config.DB_DATABASE}")
    if args.pages:
        logger.info(f"  Pages override: {args.pages}")
    if args.maker:
        logger.info(f"  Maker filter: {args.maker}")
    if args.debug:
        logger.info("  Log level: DEBUG")

    wait_for_db()

    if args.reenrich:
        logger.info(f"Running re-enrich mode (limit={args.limit})")
        run_reenrich(limit=args.limit)
        return

    if args.once:
        logger.info("Running in one-shot mode")
        run_once(pages=args.pages, maker=args.maker)
        return

    run_once(pages=args.pages, maker=args.maker)
    logger.info("Starting scheduler...")
    start_scheduler()


if __name__ == "__main__":
    main()
