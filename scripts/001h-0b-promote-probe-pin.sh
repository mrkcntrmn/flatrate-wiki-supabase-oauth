#!/usr/bin/env bash
# FORUM-MEMBER-DASHBOARD-001H.0B — pin oauth probe SHA into PikaPods extensions/list.
# Does NOT Soft Update / restart (PikaPods UI). Does NOT print secrets.
set -euo pipefail

TARGET_SHA="${TARGET_SHA:-0be5e64ad3d85e4bc865edf92f0b913a8e2bfbab}"
# Prefer main after merge. Until then, Packagist exposes the pushed feature branch as:
#   dev-feat-forum-member-dashboard-001h-community-activity-map
PIN_REF="${PIN_REF:-dev-feat-forum-member-dashboard-001h-community-activity-map}"
NEW_LINE="flatrate/wiki-supabase-oauth:${PIN_REF}#${TARGET_SHA}"

EVID="${EVID:-/tmp/forum-member-dashboard-001h-0b-promote}"
mkdir -p "$EVID"

python3 - <<PY
from pathlib import Path
vals={}
for line in Path('/home/ilove/dev/flatrate-wiki/.env').read_text().splitlines():
    s=line.strip()
    if not s or s.startswith('#') or '=' not in s: continue
    k,v=s.split('=',1)
    vals[k]=v.strip().strip('"').strip("'")
for k in ('PIKAPODS_HOSTNAME','PIKAPODS_USERNAME','PIKAPODS_PASSWORD'):
    if not vals.get(k):
        raise SystemExit(f'missing {k}')
Path('${EVID}/.pika.env').write_text(
    'PIKA_HOST='+vals['PIKAPODS_HOSTNAME']+'\n'
    'PIKA_USER='+vals['PIKAPODS_USERNAME']+'\n'
    'PIKA_PASS='+vals['PIKAPODS_PASSWORD']+'\n'
)
print('credentials_staged')
PY
# shellcheck disable=SC1091
source "${EVID}/.pika.env"
rm -f "${EVID}/.pika.env"

PWFILE=$(mktemp); ASKPASS=$(mktemp); SSH_CFG=$(mktemp)
trap 'rm -f "$PWFILE" "$ASKPASS" "$SSH_CFG"' EXIT
printf '%s\n' "$PIKA_PASS" > "$PWFILE"; chmod 600 "$PWFILE"
cat > "$ASKPASS" <<EOF
#!/bin/sh
cat -- '$PWFILE'
EOF
chmod 700 "$ASKPASS"
cat > "$SSH_CFG" <<'SSHEOF'
Host *
  PasswordAuthentication yes
  PubkeyAuthentication no
  PreferredAuthentications password
  IdentitiesOnly yes
  NumberOfPasswordPrompts 1
SSHEOF
chmod 600 "$SSH_CFG"

run_sftp() {
  printf '%s\n' "$1" | setsid -w env \
    SSH_ASKPASS="$ASKPASS" SSH_ASKPASS_REQUIRE=force DISPLAY="${DISPLAY:-:0}" \
    sftp -F "$SSH_CFG" -P 22 \
      -oPreferredAuthentications=password -oPubkeyAuthentication=no -oIdentitiesOnly=yes \
      "${PIKA_USER}@${PIKA_HOST}"
}

BEFORE="${EVID}/extensions-list.before"
CANDIDATE="${EVID}/extensions-list.candidate"
READBACK="${EVID}/extensions-list.readback"
LIVECHK="${EVID}/extensions-list.livechk"

echo "Downloading live extensions/list…"
run_sftp "get data/extensions/list ${BEFORE}" >"${EVID}/sftp-get-before.log" 2>&1
cp "$BEFORE" "$CANDIDATE"

python3 - <<PY
from pathlib import Path
before = Path("${BEFORE}").read_text()
if not before.endswith("\n"):
    raise SystemExit("STOP=BEFORE_MISSING_TRAILING_NEWLINE")
new_line = "${NEW_LINE}"
out = []
hits = 0
for line in before.splitlines(keepends=True):
    raw = line.rstrip("\n")
    if raw.startswith("flatrate/wiki-supabase-oauth:"):
        hits += 1
        out.append(new_line + "\n")
    else:
        out.append(line if line.endswith("\n") else line + "\n")
if hits != 1:
    raise SystemExit(f"STOP=OAUTH_LINE_COUNT={hits}")
text = "".join(out)
Path("${CANDIDATE}").write_text(text)
old = [l for l in before.splitlines() if l.startswith("flatrate/wiki-supabase-oauth:")][0]
print("OLD_PIN="+old)
print("NEW_PIN="+new_line)
print("CANDIDATE_SHA256="+__import__("hashlib").sha256(text.encode()).hexdigest())
PY

echo "Re-checking live for drift…"
run_sftp "get data/extensions/list ${LIVECHK}" >"${EVID}/sftp-get-livechk.log" 2>&1
if ! cmp -s "$BEFORE" "$LIVECHK"; then
  echo "STOP=PRODUCTION_DRIFT_BEFORE_UPLOAD"
  diff -u "$BEFORE" "$LIVECHK" || true
  exit 5
fi

echo "Uploading candidate…"
run_sftp "put ${CANDIDATE} data/extensions/list" >"${EVID}/sftp-put.log" 2>&1
run_sftp "get data/extensions/list ${READBACK}" >"${EVID}/sftp-readback.log" 2>&1
if ! cmp -s "$CANDIDATE" "$READBACK"; then
  echo "STOP=READBACK_MISMATCH — restoring before"
  run_sftp "put ${BEFORE} data/extensions/list" >"${EVID}/sftp-restore.log" 2>&1
  exit 6
fi

echo "LIST_UPLOAD=PASS"
echo "LIST_READBACK=PASS"
echo "EVIDENCE_DIR=${EVID}"
echo
echo "NEXT_REQUIRED_IN_PIKAPODS_UI="
echo "  1) Soft Update (so Composer installs ${NEW_LINE})"
echo "  2) Restart pod"
echo "THEN: ./scripts/001h-0b-prove-ingress.sh"
echo "AND:  cd /home/ilove/dev/flatrate-wiki && npm run check:forum"
