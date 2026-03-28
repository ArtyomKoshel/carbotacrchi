import os


class Config:
    DB_HOST = os.getenv("DB_HOST", "localhost")
    DB_PORT = int(os.getenv("DB_PORT", "3306"))
    DB_DATABASE = os.getenv("DB_DATABASE", "auction_bot")
    DB_USERNAME = os.getenv("DB_USERNAME", "carbot")
    DB_PASSWORD = os.getenv("DB_PASSWORD", "carbot_pass")

    KBCHA_ENABLED = os.getenv("KBCHA_ENABLED", "true").lower() == "true"
    KBCHA_INTERVAL_MINUTES = int(os.getenv("KBCHA_INTERVAL_MINUTES", "60"))
    KBCHA_PROXY = os.getenv("KBCHA_PROXY", "")
    KBCHA_MAX_PAGES = int(os.getenv("KBCHA_MAX_PAGES", "5"))

    ENCAR_ENABLED = os.getenv("ENCAR_ENABLED", "false").lower() == "true"
    ENCAR_INTERVAL_MINUTES = int(os.getenv("ENCAR_INTERVAL_MINUTES", "30"))
    ENCAR_PROXY = os.getenv("ENCAR_PROXY", "")

    USD_KRW_RATE = float(os.getenv("USD_KRW_RATE", "1350"))

    LOG_LEVEL = os.getenv("LOG_LEVEL", "INFO")
    BATCH_SIZE = int(os.getenv("BATCH_SIZE", "100"))
    REQUEST_DELAY = float(os.getenv("REQUEST_DELAY", "2.0"))
