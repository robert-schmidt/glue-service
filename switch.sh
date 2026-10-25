#!/usr/bin/env bash
# Two switches, both stored in .env and applied immediately.
#   ./switch.sh glue                  WMS -> glue -> ERP   (glue, glue-worker, glue-db running)
#   ./switch.sh direct                WMS -> ERP           (glue stopped, systems as they were)
#   ./switch.sh telemetry local       Grafana LGTM on http://localhost:3300
#   ./switch.sh telemetry azure       Application Insights (set APPLICATIONINSIGHTS_CONNECTION_STRING in .env)
#   ./switch.sh telemetry console     collector log only
#   ./switch.sh                       show the current settings
set -euo pipefail
cd "$(dirname "$0")"

get() { grep -E "^$1=" .env | cut -d= -f2- || true; }
put() { grep -vE "^$1=" .env > .env.tmp || true; echo "$1=$2" >> .env.tmp; mv .env.tmp .env; }

if [ "${1:-} ${2:-}" = "telemetry azure" ] && [ -z "$(get APPLICATIONINSIGHTS_CONNECTION_STRING)" ]; then
  echo "set APPLICATIONINSIGHTS_CONNECTION_STRING in .env first (Azure portal > Application Insights > Overview)"; exit 1
fi

case "${1:-} ${2:-}" in
  "glue ")              put WMS_ERP_URL http://glue:8080/erp ;;
  "direct ")            put WMS_ERP_URL http://erp:8080 ;;
  "telemetry local"|"telemetry azure"|"telemetry console") put TELEMETRY "$2" ;;
  " ")                  grep -vE '^(#|$)' .env; exit 0 ;;
  *)                    sed -n '2,8p' "$0"; exit 1 ;;
esac

profiles=()
[ "$(get WMS_ERP_URL)" = http://glue:8080/erp ] && profiles+=(glue) || docker compose --profile glue stop glue glue-worker glue-db
[ "$(get TELEMETRY)" = local ] && profiles+=(lgtm) || docker compose --profile lgtm stop lgtm
put COMPOSE_PROFILES "$(IFS=,; echo "${profiles[*]-}")"

docker compose up -d --remove-orphans
echo "integration: $(get WMS_ERP_URL) | telemetry: $(get TELEMETRY)"
