"""Encar parser constants and configuration."""

from __future__ import annotations

# API limits and pagination
PAGE_SIZE = 100
BATCH_SIZE = 20  # batch_details API hard-caps at 20 items
MAX_SAFE_OFFSET = 9900  # Encar search API (Elasticsearch) caps at ~10k results per query

# Error handling
RETRY_STATUS_CODES = {401, 403, 407, 408, 410, 429, 502, 503, 504}
PROXY_ERROR_TYPES = ("ProxyError", "ConnectError", "ReadTimeout")
MAX_JOB_RETRIES = 3
MAX_PROXY_REGENS = 5

# Coverage thresholds
MIN_DELIST_COVERAGE = 95.0

# Outer damage status mapping
OUTER_STATUS = {"W": "panel", "X": "replaced", "A": "scratch", "U": "damaged", "C": "corrosion"}

# Inner damage codes
_BAD_INNER = {
    "engine": "engine",
    "transmission": "transmission", 
    "brake": "brake",
    "suspension": "suspension",
    "steering": "steering",
    "exhaust": "exhaust",
    "cooling": "cooling",
    "electrical": "electrical",
    "body": "body",
}

# Source identifier
SOURCE = "encar"
