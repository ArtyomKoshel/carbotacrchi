#!/usr/bin/env bash
# One-time prod setup of the collector (docs/refactor/cutover.md, steps 1-2), run by the owner:
#   bash docker/railway/setup-collector.sh
# 1. collector schema + least-privilege MySQL user (password generated here, never printed)
# 2. Railway service `collector`: built from collector/ in this repo, shadow-safe COLLECTOR_CLAIM=tagged
# The web service is not touched: COLLECTOR_MODE stays off until the cutover.
set -euo pipefail
cd "$(dirname "$0")/../.."

project=14fd1d14-037c-4b61-b483-603ded752607
pw_file="${COLLECTOR_PW_FILE:-$HOME/.carbot-collector-db-password}"
if [ ! -s "$pw_file" ]; then
  (umask 077; openssl rand -hex 24 > "$pw_file")
fi
pw=$(cat "$pw_file")

echo "== 1/3 collector database and user"
printf '%s' "CREATE DATABASE IF NOT EXISTS collector CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'collector'@'%' IDENTIFIED BY '${pw}';
ALTER USER 'collector'@'%' IDENTIFIED BY '${pw}';
GRANT ALL PRIVILEGES ON collector.* TO 'collector'@'%';
GRANT SELECT ON railway.parse_filters TO 'collector'@'%';
GRANT SELECT, UPDATE (status, progress, result, updated_at) ON railway.parse_jobs TO 'collector'@'%';
FLUSH PRIVILEGES;
SHOW GRANTS FOR 'collector'@'%';" \
  | bash ~/.claude/skills/railway-prod-db/scripts/prod-sql.sh carbot --write | sed "s/${pw}/<hidden>/g"

echo "== 2/3 service with its variables (no source yet: nothing builds)"
railway link -p "$project" -e production -s carbotacrchi >/dev/null
railway add --service collector \
  --variables "COLLECTOR_DATABASE_URL=mysql+aiomysql://collector:${pw}@mysql-6oym.railway.internal:3306/collector" \
  --variables "COLLECTOR_CORE_SCHEMA=railway" \
  --variables "COLLECTOR_CLAIM=tagged" \
  --variables "COLLECTOR_CONCURRENCY=8" \
  --variables 'FLOPPY_USERNAME=${{carbotacrchi.FLOPPY_USERNAME}}' \
  --variables 'FLOPPY_PASSWORD=${{carbotacrchi.FLOPPY_PASSWORD}}' \
  --variables 'FLOPPY_HOST=${{carbotacrchi.FLOPPY_HOST}}' \
  --variables "RAILWAY_DEPLOYMENT_DRAINING_SECONDS=40" \
  --variables "LOG_LEVEL=INFO" >/dev/null
echo "service collector created"

echo "== 3/3 source: this repo, branch main, root directory collector/ (one commit, one build)"
railway environment edit -e production -m "collector service: source and build" \
  --service-config collector source.repo "ArtyomKoshel/carbotacrchi" \
  --service-config collector source.branch "main" \
  --service-config collector source.rootDirectory "collector" \
  --service-config collector build.builder "DOCKERFILE" \
  --service-config collector build.dockerfilePath "Dockerfile"

echo "done. First build starts now; its log should end with 'migrations applied: 001_ingest_lots, ...'."
