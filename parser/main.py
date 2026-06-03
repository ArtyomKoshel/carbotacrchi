import argparse
import logging
import time

from dotenv import load_dotenv

load_dotenv()

from config import Config
from logging_config import setup_logging
from repository import LotRepository
import parsers  # noqa: F401 — triggers parser registration
from parsers.registry import get_enabled, get_all
from scheduler import start_scheduler

logger = logging.getLogger("parser")


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
    parser.add_argument("--sample", type=int, default=None,
                        help="Sample mode: fetch N lots per model group (e.g. --sample 7). "
                             "Covers all model groups with a small representative dataset.")
    parser.add_argument("--source", type=str, default=None,
                        help="Run only this source parser (e.g. --source=encar or --source=kbcha)")
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


def run_once(
    pages: int | None = None,
    maker: str | None = None,
    sample: int | None = None,
    source: str | None = None,
) -> None:
    repo = LotRepository()
    try:
        # When --source is explicit, search all registered parsers (even disabled ones)
        registry = get_all() if source else get_enabled()
        for key, reg in registry.items():
            # Filter by --source if specified
            if source and key != source:
                logger.info(f"{reg.cls.__name__} ({key}): skipped (--source={source})")
                continue
            parser = reg.cls(repo)
            # sample mode is Encar-only — skip parsers that don't support it
            if sample and not hasattr(parser, 'run_sample'):
                logger.info(f"{parser.get_source_name()}: skipped (sample mode not supported)")
                continue
            kwargs = dict(max_pages=pages, maker_filter=maker)
            if sample:
                kwargs['sample'] = sample
            result = parser.run(**kwargs)
            if isinstance(result, dict):
                logger.info(
                    f"{parser.get_source_name()}: {result.get('total', 0)} lots imported "
                    f"(new={result.get('new', 0)}, updated={result.get('updated', 0)}, "
                    f"errors={result.get('errors', 0)})"
                )
            else:
                logger.info(f"{parser.get_source_name()}: {result} lots imported")
    finally:
        repo.close()


def run_reenrich(limit: int | None = None) -> None:
    repo = LotRepository()
    try:
        for key, reg in get_enabled().items():
            parser = reg.cls(repo)
            try:
                count = parser.run_reenrich(limit=limit)
                logger.info(f"{parser.get_source_name()} re-enrich: {count} lots updated")
            except NotImplementedError:
                logger.info(f"{parser.get_source_name()}: re-enrich not supported, skipping")
    finally:
        repo.close()


def main() -> None:
    args = _parse_args()
    setup_logging(debug=args.debug)

    logger.info("Parser service starting...")
    for key, reg in get_enabled().items():
        logger.info(f"  {key}: enabled  schedule={reg.schedule or f'interval:{reg.interval_minutes}m'}")
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

    if args.once or args.sample:
        mode = f"sample/{args.sample}" if args.sample else "one-shot"
        logger.info(f"Running in {mode} mode")
        run_once(pages=args.pages, maker=args.maker, sample=args.sample, source=args.source)
        return

    logger.info("Starting scheduler (first run in ~60s via job queue)...")
    start_scheduler()


if __name__ == "__main__":
    main()
