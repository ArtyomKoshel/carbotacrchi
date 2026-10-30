# CarBot collector service (docs/adr/0001). Build context: the collector/ directory.
FROM python:3.13-slim AS base

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    UV_COMPILE_BYTECODE=1 \
    UV_LINK_MODE=copy \
    UV_PROJECT_ENVIRONMENT=/app/.venv

COPY --from=ghcr.io/astral-sh/uv:0.13.4 /uv /usr/local/bin/uv

WORKDIR /app

# Dependencies first (cached layer), runtime only: no dev / legacy groups.
# The che168 extra (curl_cffi) is in: a collector with COLLECTOR_SOURCES=che168 runs che168 jobs.
COPY pyproject.toml uv.lock ./
RUN uv sync --frozen --no-default-groups --extra che168 --no-install-project

COPY src ./src
COPY migrations ./migrations
RUN uv sync --frozen --no-default-groups --extra che168 \
    && useradd --system --uid 10001 collector \
    && chown -R collector /app

USER collector
ENV PATH="/app/.venv/bin:$PATH"

# SIGTERM (a redeploy) stops the sweep after the current page and hands the job back.
STOPSIGNAL SIGTERM
CMD ["python", "-m", "collector", "serve"]
