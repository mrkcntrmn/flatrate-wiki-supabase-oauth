#!/usr/bin/env bash
# FORUM-MEMBER-DASHBOARD-001H.0B — create/update narrow Request Header Transform Rule.
# Requires: CLOUDFLARE_API_TOKEN, CLOUDFLARE_ZONE_ID (flatrate.wiki zone).
# Does NOT enable Managed Transform "Add visitor location headers".
set -euo pipefail

: "${CLOUDFLARE_API_TOKEN:?set CLOUDFLARE_API_TOKEN}"
: "${CLOUDFLARE_ZONE_ID:?set CLOUDFLARE_ZONE_ID}"

API="https://api.cloudflare.com/client/v4"
AUTH=( -H "Authorization: Bearer ${CLOUDFLARE_API_TOKEN}" -H "Content-Type: application/json" )

NEW_RULE_JSON='{
  "ref": "flatrate_forum_presence_coarse_region",
  "description": "FlatRate forum presence coarse region",
  "expression": "(http.host eq \"forum.flatrate.wiki\" and http.request.method eq \"POST\" and http.request.uri.path eq \"/api/flatrate/community-presence/touch\")",
  "action": "rewrite",
  "action_parameters": {
    "headers": {
      "X-FlatRate-Country": {
        "operation": "set",
        "expression": "ip.src.country"
      },
      "X-FlatRate-Region-Code": {
        "operation": "set",
        "expression": "ip.src.region_code"
      }
    }
  }
}'

echo "Listing zone rulesets…"
RULESETS=$(curl -fsS "${AUTH[@]}" "${API}/zones/${CLOUDFLARE_ZONE_ID}/rulesets")
export RULESETS
PHASE_ID=$(python3 - <<'PY'
import json, os
data = json.loads(os.environ["RULESETS"])
for r in data.get("result", []):
    if r.get("phase") == "http_request_late_transform" and r.get("kind") == "zone":
        print(r["id"])
        break
PY
)

if [[ -z "${PHASE_ID}" ]]; then
  echo "Creating http_request_late_transform ruleset…"
  CREATE_PAYLOAD=$(NEW_RULE_JSON="$NEW_RULE_JSON" python3 - <<'PY'
import json, os
rule = json.loads(os.environ["NEW_RULE_JSON"])
print(json.dumps({
  "name": "Zone-level Late Transform Ruleset",
  "kind": "zone",
  "phase": "http_request_late_transform",
  "rules": [rule],
}))
PY
)
  curl -fsS "${AUTH[@]}" -X POST "${API}/zones/${CLOUDFLARE_ZONE_ID}/rulesets" \
    --data "${CREATE_PAYLOAD}" | tee /tmp/flatrate-001h-0b-cf-create.json
  echo
  echo "CREATED_NEW_RULESET=true"
  exit 0
fi

echo "Phase ruleset id=${PHASE_ID}"
EXISTING=$(curl -fsS "${AUTH[@]}" "${API}/zones/${CLOUDFLARE_ZONE_ID}/rulesets/${PHASE_ID}")
echo "${EXISTING}" > /tmp/flatrate-001h-0b-cf-ruleset-before.json

UPDATE_PAYLOAD=$(NEW_RULE_JSON="$NEW_RULE_JSON" python3 - <<'PY'
import json, os
existing = json.load(open("/tmp/flatrate-001h-0b-cf-ruleset-before.json"))
rules = list(existing["result"].get("rules") or [])
new_rule = json.loads(os.environ["NEW_RULE_JSON"])
out = []
replaced = False
keep = ("id", "ref", "description", "expression", "action", "action_parameters", "enabled")
for r in rules:
    if r.get("ref") == "flatrate_forum_presence_coarse_region" or r.get("description") == "FlatRate forum presence coarse region":
        if "id" in r:
            new_rule["id"] = r["id"]
        out.append(new_rule)
        replaced = True
    else:
        out.append({k: r[k] for k in keep if k in r})
if not replaced:
    out.append(new_rule)
print(json.dumps({"rules": out}))
PY
)

echo "Updating ruleset (append/replace FlatRate rule only)…"
curl -fsS "${AUTH[@]}" -X PUT "${API}/zones/${CLOUDFLARE_ZONE_ID}/rulesets/${PHASE_ID}" \
  --data "${UPDATE_PAYLOAD}" | tee /tmp/flatrate-001h-0b-cf-ruleset-after.json
echo
echo "CLOUDFLARE_REQUEST_HEADER_TRANSFORM=UPDATED"
echo "EVIDENCE=/tmp/flatrate-001h-0b-cf-ruleset-after.json"
