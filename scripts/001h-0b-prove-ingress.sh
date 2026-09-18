#!/usr/bin/env bash
# FORUM-MEMBER-DASHBOARD-001H.0B — production ingress proof against live probe.
# Loads FORUM_ADMIN_* from flatrate-wiki .env (does not print secrets).
set -euo pipefail

ROOT_WIKI="${ROOT_WIKI:-/home/ilove/dev/flatrate-wiki}"
ENV_FILE="${ENV_FILE:-${ROOT_WIKI}/.env}"
OUT_DIR="${OUT_DIR:-/tmp/forum-member-dashboard-001h-0b-proof}"
mkdir -p "${OUT_DIR}"

python3 - <<PY
from pathlib import Path
env = Path("${ENV_FILE}")
vals = {}
for line in env.read_text().splitlines():
    s = line.strip()
    if not s or s.startswith("#") or "=" not in s:
        continue
    k, v = s.split("=", 1)
    vals[k] = v.strip().strip('"').strip("'")
need = ["FORUM_ADMIN_API_KEY", "FORUM_ADMIN_USER_ID"]
missing = [k for k in need if not vals.get(k)]
if missing:
    raise SystemExit("missing " + ",".join(missing))
Path("${OUT_DIR}/.admin.env").write_text(
    "FORUM_ADMIN_API_KEY=" + vals["FORUM_ADMIN_API_KEY"] + "\n"
    "FORUM_ADMIN_USER_ID=" + vals["FORUM_ADMIN_USER_ID"] + "\n"
)
print("admin env staged")
PY
# shellcheck disable=SC1091
source "${OUT_DIR}/.admin.env"
rm -f "${OUT_DIR}/.admin.env"

BASE="https://forum.flatrate.wiki"
PATH_TOUCH="/api/flatrate/community-presence/touch"
ORIGIN_HOST="${ORIGIN_HOST:-simple-lemming.pikapod.net}"

auth_hdr=( -H "Authorization: Token ${FORUM_ADMIN_API_KEY}; userId=${FORUM_ADMIN_USER_ID}" -H "Content-Type: application/json" )

echo "== Cloudflare-routed probe with spoofed client headers =="
SPOOF=$(curl -sS -D "${OUT_DIR}/spoof.headers" -o "${OUT_DIR}/spoof.body" -w '%{http_code}' \
  -X POST "${BASE}${PATH_TOUCH}" \
  "${auth_hdr[@]}" \
  -H "X-FlatRate-Country: ZZ" \
  -H "X-FlatRate-Region-Code: ZZ" \
  --data '{}')
echo "HTTP=${SPOOF}"
cat "${OUT_DIR}/spoof.body"; echo
python3 - <<PY
import json
from pathlib import Path
body = json.loads(Path("${OUT_DIR}/spoof.body").read_text())
lines = []
def put(k, v):
    lines.append(f"{k}={v}")
put("HTTP_STATUS", "${SPOOF}")
put("PROBE", body.get("probe"))
put("COUNTRY_HEADER_PRESENT", body.get("country_header_present"))
put("REGION_HEADER_PRESENT", body.get("region_header_present"))
put("COUNTRY", body.get("country"))
put("REGION_CODE", body.get("region_code"))
country = body.get("country")
region = body.get("region_code")
put("CLIENT_COUNTRY_HEADER_OVERWRITE", "PASS" if country not in (None, "ZZ") else "FAIL")
put("CLIENT_REGION_HEADER_OVERWRITE", "PASS" if region not in (None, "ZZ") else "FAIL")
put("PHP_COUNTRY_HEADER_PROOF", "PASS" if country == "US" else "FAIL")
put("PHP_REGION_HEADER_PROOF", "PASS" if isinstance(region, str) and len(region) == 2 and region != "ZZ" else "FAIL")
Path("${OUT_DIR}/spoof.summary.txt").write_text("\\n".join(lines) + "\\n")
print(Path("${OUT_DIR}/spoof.summary.txt").read_text())
PY

echo "== Direct origin bypass attempts =="
set +e
curl -sS -m 10 -o "${OUT_DIR}/origin-host.body" -w 'ORIGIN_HOST_HTTP=%{http_code}\n' \
  -X POST "https://${ORIGIN_HOST}${PATH_TOUCH}" \
  "${auth_hdr[@]}" \
  -H "Host: forum.flatrate.wiki" \
  -H "X-FlatRate-Country: ZZ" \
  -H "X-FlatRate-Region-Code: ZZ" \
  --data '{}' > "${OUT_DIR}/origin-host.meta" 2>"${OUT_DIR}/origin-host.err"
ORIGIN_IP=$(getent ahostsv4 "${ORIGIN_HOST}" | awk '{print $1; exit}')
if [[ -n "${ORIGIN_IP}" ]]; then
  curl -sS -m 10 -o "${OUT_DIR}/origin-resolve.body" -w 'ORIGIN_RESOLVE_HTTP=%{http_code}\n' \
    --resolve "forum.flatrate.wiki:443:${ORIGIN_IP}" \
    -X POST "https://forum.flatrate.wiki${PATH_TOUCH}" \
    "${auth_hdr[@]}" \
    -H "X-FlatRate-Country: ZZ" \
    -H "X-FlatRate-Region-Code: ZZ" \
    --data '{}' > "${OUT_DIR}/origin-resolve.meta" 2>"${OUT_DIR}/origin-resolve.err"
fi
set -e
echo "origin-host:"; cat "${OUT_DIR}/origin-host.meta" 2>/dev/null; head -5 "${OUT_DIR}/origin-host.err" 2>/dev/null || true
echo "origin-resolve:"; cat "${OUT_DIR}/origin-resolve.meta" 2>/dev/null; head -5 "${OUT_DIR}/origin-resolve.err" 2>/dev/null || true

echo "== Forum health =="
curl -sS -o /dev/null -w 'FORUM_HOME=%{http_code}\n' "https://forum.flatrate.wiki/"
curl -sS -o /dev/null -w 'API_FORUM=%{http_code}\n' "https://forum.flatrate.wiki/api"

echo "EVIDENCE_DIR=${OUT_DIR}"
echo "NOTE=Set DIRECT_ORIGIN_BYPASS=BLOCKED only if origin attempts fail closed (timeout/refuse/403 without probe body)."
