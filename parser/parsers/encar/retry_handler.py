"""Universal retry handler for consistent error handling across parsers."""

from __future__ import annotations

import logging
import time as _time
from typing import Any, Callable, TypeVar

import httpx

from .constants import MAX_JOB_RETRIES, PROXY_ERROR_TYPES, RETRY_STATUS_CODES

logger = logging.getLogger(__name__)

T = TypeVar('T')


class RetryHandler:
    """Handles retries with exponential backoff for HTTP requests."""
    
    @staticmethod
    def with_retry(
        func: Callable[..., T],
        *args,
        max_retries: int = MAX_JOB_RETRIES,
        base_delay: float = 1.0,
        client: Any = None,
        **kwargs
    ) -> T:
        """Execute function with retry logic and proxy rotation on errors."""
        for attempt in range(max_retries + 1):
            try:
                return func(*args, **kwargs)
            except httpx.HTTPStatusError as e:
                if e.response.status_code in RETRY_STATUS_CODES and attempt < max_retries:
                    wait = base_delay * (2 ** attempt)  # Exponential backoff
                    logger.debug(f"HTTP {e.response.status_code} on attempt {attempt+1}/{max_retries}, retry in {wait}s")
                    _time.sleep(wait)
                    if client and hasattr(client, 'rotate_proxy'):
                        client.rotate_proxy()
                    continue
                raise
            except Exception as e:
                error_type = type(e).__name__
                if error_type in PROXY_ERROR_TYPES and attempt < max_retries:
                    wait = base_delay * (2 ** attempt)
                    logger.debug(f"{error_type} on attempt {attempt+1}/{max_retries}, retry in {wait}s")
                    _time.sleep(wait)
                    if client and hasattr(client, 'rotate_proxy'):
                        client.rotate_proxy()
                    continue
                raise
    
    @staticmethod
    def should_retry_error(error: Exception) -> bool:
        """Check if error should trigger a retry."""
        if isinstance(error, httpx.HTTPStatusError):
            return error.response.status_code in RETRY_STATUS_CODES
        return type(error).__name__ in PROXY_ERROR_TYPES
    
    @staticmethod
    def handle_proxy_rotation(client: Any, error: Exception) -> None:
        """Handle proxy rotation for retryable errors."""
        if client and hasattr(client, 'rotate_proxy') and RetryHandler.should_retry_error(error):
            client.rotate_proxy()
            logger.debug(f"Rotated proxy after {type(error).__name__}")
