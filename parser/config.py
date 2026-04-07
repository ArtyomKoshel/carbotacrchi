import os


class Config:
    DB_HOST = os.getenv("DB_HOST", "localhost")
    DB_PORT = int(os.getenv("DB_PORT", "3306"))
    DB_DATABASE = os.getenv("DB_DATABASE", "auction_bot")
    DB_USERNAME = os.getenv("DB_USERNAME", "carbot")
    DB_PASSWORD = os.getenv("DB_PASSWORD", "carbot_pass")

    KBCHA_ENABLED = os.getenv("KBCHA_ENABLED", "true").lower() == "true"
    KBCHA_INTERVAL_MINUTES = int(os.getenv("KBCHA_INTERVAL_MINUTES", "60"))
    # KBCHA_SCHEDULE overrides KBCHA_INTERVAL_MINUTES when set.
    # Format: "interval:60" (minutes) or "cron:0 * * * *" (cron expression min h dom mon dow)
    KBCHA_SCHEDULE = os.getenv("KBCHA_SCHEDULE", "")
    KBCHA_PROXY = os.getenv("KBCHA_PROXY", "")
    KBCHA_PROXY_LIST: list[str] = [
        p.strip() for p in os.getenv("KBCHA_PROXY_LIST", "").split(",") if p.strip()
    ]
    KBCHA_MAX_PAGES = int(os.getenv("KBCHA_MAX_PAGES", "1"))

    LOG_FILE = os.getenv("LOG_FILE", "/app/logs/parser.log")

    REDIS_HOST = os.getenv("REDIS_HOST", "localhost")
    REDIS_PORT = int(os.getenv("REDIS_PORT", "6379"))
    REDIS_PASSWORD = os.getenv("REDIS_PASSWORD", None)

    ENCAR_ENABLED = os.getenv("ENCAR_ENABLED", "false").lower() == "true"
    ENCAR_INTERVAL_MINUTES = int(os.getenv("ENCAR_INTERVAL_MINUTES", "60"))
    ENCAR_SCHEDULE = os.getenv("ENCAR_SCHEDULE", "")
    ENCAR_MAX_PAGES = int(os.getenv("ENCAR_MAX_PAGES", "1"))
    ENCAR_PROXY = os.getenv("ENCAR_PROXY", "")

    USD_KRW_RATE = float(os.getenv("USD_KRW_RATE", "1350"))

    LOG_LEVEL = os.getenv("LOG_LEVEL", "INFO")
    BATCH_SIZE = int(os.getenv("BATCH_SIZE", "100"))
    REQUEST_DELAY = float(os.getenv("REQUEST_DELAY", "2.0"))
