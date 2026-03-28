import logging

from apscheduler.schedulers.blocking import BlockingScheduler

from config import Config
from db import DBWriter
from parsers.kbcha import KBChaParser

logger = logging.getLogger(__name__)


def run_kbcha():
    logger.info("Scheduled KBChacha import starting...")
    db = DBWriter()
    try:
        parser = KBChaParser(db)
        count = parser.run()
        logger.info(f"KBChacha import finished: {count} lots")
    except Exception as e:
        logger.error(f"KBChacha import failed: {e}")
    finally:
        db.close()


def start_scheduler():
    scheduler = BlockingScheduler()

    if Config.KBCHA_ENABLED:
        scheduler.add_job(
            run_kbcha,
            "interval",
            minutes=Config.KBCHA_INTERVAL_MINUTES,
            id="kbcha_import",
            name="KBChacha Import",
            max_instances=1,
        )
        logger.info(f"KBChacha scheduled every {Config.KBCHA_INTERVAL_MINUTES} min")

    if not scheduler.get_jobs():
        logger.warning("No parsers enabled. Exiting.")
        return

    logger.info(f"Scheduler started with {len(scheduler.get_jobs())} job(s)")
    scheduler.start()
