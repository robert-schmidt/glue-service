#!/usr/bin/env bash
# Two switches, both stored in .env and applied immediately.
#   ./switch.sh glue                  WMS -> glue -> ERP   (glue, glue-worker, glue-db running)
#   ./switch.sh direct                WMS -> ERP           (glue stopped, systems as they were)
#   ./switch.sh telemetry local       Grafana LGTM on http://localhost:3300
#   ./switch.sh telemetry azure       Application Insights (set APPLICATIONINSIGHTS_CONNECTION_STRING in .env)
#   ./switch.sh telemetry console     collector log only
#   ./switch.sh payload-log always    log every payload before the plugins, and again if one changed it
#   ./switch.sh payload-log changed   log payloads only when a plugin changed them (before + after)
#   ./switch.sh payload-log off       no payload logging
#   ./switch.sh plugins               list plugins (on / off), in the order they run
#   ./switch.sh plugin new <name>     create glue/plugins/<name>.php from a starter (live at once)
#   ./switch.sh plugin on|off <name>  enable / disable a plugin (live at once, no restart)
#   ./switch.sh                       show the current settings
set -euo pipefail
cd "$(dirname "$0")"
usage() { sed -n '2,14p' "$0"; exit 1; }
dir=glue/plugins

case "${1:-}" in
  plugins)
    for f in "$dir"/*.php "$dir"/*.php.off; do
      [ -e "$f" ] || continue
      n=$(basename "$f"); case "$n" in *.off) echo "off  ${n%.php.off}" ;; *) echo "on   ${n%.php}" ;; esac
    done | sort -k2
    exit 0 ;;
  plugin)
    n="${3:-}"; f="$dir/$n.php"
    [[ "$n" =~ ^[A-Za-z0-9._-]+$ ]] || usage
    case "${2:-}" in
      on)  [ -e "$f.off" ] || { echo "no disabled plugin $n"; exit 1; }; mv "$f.off" "$f" ;;
      off) [ -e "$f" ] || { echo "no active plugin $n"; exit 1; }; mv "$f" "$f.off" ;;
      new) [ ! -e "$f" ] && [ ! -e "$f.off" ] || { echo "plugin $n already exists"; exit 1; }
           sed "s/NAME/$n/g" > "$f" <<'PHP'
<?php
// Plugin NAME. Runs in file-name order. Disable: ./switch.sh plugin off NAME
// Events: erp.write, wms.write. ctx: event, system, method, path, id (same for every repeat of a write), status (observers only)
return [
    // Before the write. Return the payload (changed or not), or null to leave it; throw Permanent to reject the write.
    // Runs again on every retry: the same input must give the same output.
    'transform' => [
        'erp.write' => fn(array $payload, array $ctx): ?array => null,
    ],
    // After a successful write. Gets a copy; errors are logged and never fail the write.
    'observe' => [
        'erp.write' => function (array $payload, array $ctx): void {
        },
    ],
];
PHP
           echo "created $f" ;;
      *) usage ;;
    esac
    exec "$0" plugins ;;
esac

get() { grep -E "^$1=" .env | cut -d= -f2- || true; }
put() { grep -vE "^$1=" .env > .env.tmp || true; echo "$1=$2" >> .env.tmp; mv .env.tmp .env; }

if [ "${1:-} ${2:-}" = "telemetry azure" ] && [ -z "$(get APPLICATIONINSIGHTS_CONNECTION_STRING)" ]; then
  echo "set APPLICATIONINSIGHTS_CONNECTION_STRING in .env first (Azure portal > Application Insights > Overview)"; exit 1
fi

case "${1:-} ${2:-}" in
  "glue ")              put WMS_ERP_URL http://glue:8080/erp ;;
  "direct ")            put WMS_ERP_URL http://erp:8080 ;;
  "telemetry local"|"telemetry azure"|"telemetry console") put TELEMETRY "$2" ;;
  "payload-log always"|"payload-log changed"|"payload-log off") put PAYLOAD_LOG "$2" ;;
  " ")                  grep -vE '^(#|$)' .env; exit 0 ;;
  *)                    usage ;;
esac

profiles=()
[ "$(get WMS_ERP_URL)" = http://glue:8080/erp ] && profiles+=(glue) || docker compose --profile glue stop glue glue-worker glue-db
[ "$(get TELEMETRY)" = local ] && profiles+=(lgtm) || docker compose --profile lgtm stop lgtm
put COMPOSE_PROFILES "$(IFS=,; echo "${profiles[*]-}")"

docker compose up -d --remove-orphans
echo "integration: $(get WMS_ERP_URL) | telemetry: $(get TELEMETRY) | payload log: $(get PAYLOAD_LOG)"
