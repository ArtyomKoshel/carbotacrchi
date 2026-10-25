#!/usr/bin/env bash
# Cutover to the collector (docs/refactor/cutover.md, step 4), run by the owner once the
# collector service is up:   bash docker/railway/cutover-collector.sh
# Rollback: COLLECTOR_MODE=off, PARSER_ENABLED=true on carbotacrchi; COLLECTOR_CLAIM=tagged on collector.
set -euo pipefail
cd "$(dirname "$0")/../.."
sql=~/.claude/skills/railway-prod-db/scripts/prod-sql.sh

echo "== 1/4 no run in progress"
running=$(bash "$sql" carbot -e "SELECT COUNT(*) AS n FROM parse_jobs WHERE status IN ('running','pending','interrupted')" | grep -oE '^\| +[0-9]+' | tr -dc '0-9')
if [ "${running:-1}" != "0" ]; then
  echo "a parse job is active ($running): wait for it to finish, then run again" >&2
  exit 1
fi

echo "== 2/4 collector takes untagged jobs too"
railway variables -s collector -e production --set "COLLECTOR_CLAIM=all" >/dev/null

echo "== 3/4 web: the collector feeds lots, the old parser stops, nothing is purged for now"
before=$(railway deployment list -s carbotacrchi -e production 2>/dev/null | sed -n 2p | awk '{print $1}')
railway variables -s carbotacrchi -e production \
  --set "COLLECTOR_MODE=primary" \
  --set "COLLECTOR_DB_DATABASE=collector" \
  --set "PARSER_ENABLED=false" \
  --set "LOTS_PURGE_AFTER_RUNS=20" >/dev/null
echo "waiting for the web redeploy..."
for _ in $(seq 1 90); do
  line=$(railway deployment list -s carbotacrchi -e production 2>/dev/null | sed -n 2p)
  id=$(echo "$line" | awk '{print $1}')
  if [ "$id" != "$before" ]; then
    case "$line" in
      *SUCCESS*) echo "deployed: $id"; break ;;
      *FAILED*|*CRASHED*) echo "web deploy failed: $line" >&2; exit 1 ;;
    esac
  fi
  sleep 20
done

echo "== 4/4 skip the (empty) shadow buffer, remember the cutover point"
# MSYS_NO_PATHCONV: Git Bash would turn /var/www/... into a Windows path.
MSYS_NO_PATHCONV=1 railway ssh -s carbotacrchi -e production -- php /var/www/html/artisan ingest:cutover </dev/null

echo "done. Start a full run from /admin (Площадки → Encar → Запустить прогон)."
