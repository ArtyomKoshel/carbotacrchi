#!/usr/bin/env bash
# Replace the dev database `carbot` with a dump, then migrate it to the served code and remap the catalog.
# Other databases on the server (tests, prodbak, collector) are left alone.
#
#   docker/dev/load-dump.sh prod                 # from Railway prod MySQL, read only (needs `railway login`)
#   docker/dev/load-dump.sh file dump.sql[.gz]   # from a file
#
# The prod dump streams inside carbot-dev-mysql-1, never through the Windows shell or a file on disk;
# prod credentials exist only in the environment of `railway run`.
set -euo pipefail

src=${1:-}
file=${2:-}
[ "$src" = file ] && file=$(cd "$(dirname "$file")" && pwd)/$(basename "$file")
cd "$(dirname "$0")"

dev_db=carbot-dev-mysql-1
load="mysql -uroot -proot-local carbot"

case $src in
  prod) ;;
  file) [ -f "$file" ] || { echo "no such file: $file" >&2; exit 2; } ;;
  *) echo "usage: $0 prod | file <dump.sql[.gz]>" >&2; exit 2 ;;
esac

echo "== stop web"
docker compose -f compose.yml stop web

echo "== recreate database carbot"
docker exec "$dev_db" mysql -uroot -proot-local -e \
  "DROP DATABASE IF EXISTS carbot; CREATE DATABASE carbot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "== load $src → carbot"
if [ "$src" = prod ]; then
  # vars are named here and read by docker from the railway run environment
  dump='mysqldump -h"$RAILWAY_TCP_PROXY_DOMAIN" -P"$RAILWAY_TCP_PROXY_PORT" -u"$MYSQLUSER" -p"$MYSQLPASSWORD" --single-transaction --quick --no-tablespaces --set-gtid-purged=OFF --routines --triggers --column-statistics=0 "$MYSQLDATABASE"'
  railway run -p 14fd1d14-037c-4b61-b483-603ded752607 -e production -s MySQL-6oyM -- \
    docker exec -e RAILWAY_TCP_PROXY_DOMAIN -e RAILWAY_TCP_PROXY_PORT -e MYSQLUSER -e MYSQLPASSWORD -e MYSQLDATABASE \
    "$dev_db" sh -c "$dump | $load"
elif [[ $file == *.gz ]]; then
  docker exec -i "$dev_db" sh -c "gunzip | $load" < "$file"
else
  docker exec -i "$dev_db" sh -c "$load" < "$file"
fi

echo "== start web: migrations of the served code run on start"
since=$(date -u +%Y-%m-%dT%H:%M:%SZ)
docker compose -f compose.yml up -d --build web
# output captured first: `grep -q` on a pipe would close it early and fail the pipe under pipefail
until grep -q '\[dev\] code:' <<<"$(docker compose -f compose.yml logs web --since "$since" 2>&1)"; do sleep 5; done

if grep -q '^catalog:remap' <<<"$(docker compose -f compose.yml exec -T web php artisan list --raw)"; then
  echo "== catalog:remap"
  docker compose -f compose.yml exec -T web php artisan catalog:remap
fi

docker exec "$dev_db" mysql -uroot -proot-local -N -e \
  "SELECT CONCAT('lots: ', COUNT(*), ', active: ', SUM(is_active)) FROM carbot.lots"
