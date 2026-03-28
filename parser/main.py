import logging
import sys
import time

from dotenv import load_dotenv

load_dotenv()

from config import Config
from repository import LotRepository
from parsers.kbcha import KBChaParser
from scheduler import start_scheduler

logging.basicConfig(
    level=getattr(logging, Config.LOG_LEVEL, logging.INFO),
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
    stream=sys.stdout,
)
logger = logging.getLogger("parser")


def wait_for_db(max_retries: int = 30, delay: float = 2.0):
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


def run_once():
    repo = LotRepository()
    try:
        if Config.KBCHA_ENABLED:
            parser = KBChaParser(repo)
            count = parser.run()
            logger.info(f"KBChacha: {count} lots imported")
    finally:
        repo.close()


def main():
    logger.info("Parser service starting...")
    logger.info(f"  KBChacha: {'enabled' if Config.KBCHA_ENABLED else 'disabled'}")
    logger.info(f"  Encar:    {'enabled' if Config.ENCAR_ENABLED else 'disabled'}")
    logger.info(f"  DB: {Config.DB_HOST}:{Config.DB_PORT}/{Config.DB_DATABASE}")

    wait_for_db()

    if "--once" in sys.argv:
        logger.info("Running in one-shot mode")
        run_once()
        return

    run_once()

    logger.info("Starting scheduler...")
    start_scheduler()


if __name__ == "__main__":
    main()
